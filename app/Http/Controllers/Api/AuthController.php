<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;

class AuthController extends Controller
{
    /**
     * Register a new user (consumer or farmer).
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'email'         => 'required|email|unique:users,email',
            'phone'         => 'nullable|string|max:20',
            'password'      => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
            'role'          => 'required|in:consumer,farmer',
            'referral_code' => 'nullable|string|exists:users,referral_code',
        ]);

        $user = User::create([
            'name'          => $validated['name'],
            'email'         => $validated['email'],
            'phone'         => $validated['phone'] ?? null,
            'password'      => $validated['password'],
            'role'          => $validated['role'],
            'referral_code' => strtoupper(\Illuminate\Support\Str::random(8)),
        ]);

        // Handle referral
        if (!empty($validated['referral_code'])) {
            $referrer = User::where('referral_code', $validated['referral_code'])->first();
            if ($referrer) {
                \App\Models\Referral::create([
                    'referrer_id' => $referrer->id,
                    'referred_id' => $user->id,
                    'status'      => 'pending',
                ]);
            }
        }

        event(new Registered($user));

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful. Please verify your email.',
            'user'    => $user,
            'token'   => $token,
        ], 201);
    }

    /**
     * Login with email and password.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($validated)) {
            // Log failed attempt
            $user = User::where('email', $validated['email'])->first();
            if ($user) {
                $this->logActivity($request, $user, 'failed');
            }
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $user = Auth::user();

        if ($user->status === 'suspended') {
            Auth::logout();
            return response()->json(['message' => 'Your account has been suspended.'], 403);
        }

        // Revoke old tokens
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        // Log successful login
        $this->logActivity($request, $user, 'success');

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $user->load('farmerProfile', 'kycVerification'),
            'token'   => $token,
        ]);
    }

    private function logActivity($request, $user, string $status): void
    {
        $agent    = $request->userAgent() ?? '';
        $device   = $this->detectDevice($agent);
        $browser  = $this->detectBrowser($agent);
        $platform = $this->detectPlatform($agent);

        \App\Models\LoginActivity::create([
            'user_id'      => $user->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => substr($agent, 0, 255),
            'device'       => $device,
            'browser'      => $browser,
            'platform'     => $platform,
            'status'       => $status,
            'logged_in_at' => now(),
        ]);
    }

    private function detectDevice(string $agent): string
    {
        if (stripos($agent, 'mobile') !== false) return 'Mobile';
        if (stripos($agent, 'tablet') !== false) return 'Tablet';
        return 'Desktop';
    }

    private function detectBrowser(string $agent): string
    {
        foreach (['Chrome', 'Firefox', 'Safari', 'Edge', 'Opera', 'MSIE'] as $browser) {
            if (stripos($agent, $browser) !== false) return $browser;
        }
        return 'Unknown';
    }

    private function detectPlatform(string $agent): string
    {
        foreach (['Windows', 'Mac', 'Linux', 'Android', 'iOS', 'iPhone', 'iPad'] as $platform) {
            if (stripos($agent, $platform) !== false) return $platform;
        }
        return 'Unknown';
    }

    /**
     * Logout the authenticated user.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Get authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->load('farmerProfile', 'kycVerification'),
        ]);
    }

    /**
     * Send email verification notification.
     */
    public function sendVerificationEmail(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification email sent.']);
    }

    /**
     * Verify email address.
     */
    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        if (!hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json(['message' => 'Invalid verification link.'], 400);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return response()->json(['message' => 'Email verified successfully.']);
    }

    /**
     * Send password reset link.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink($request->only('email'));

        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json(['message' => __($status)], 400);
        }

        return response()->json(['message' => 'Password reset link sent to your email.']);
    }

    /**
     * Reset password.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 400);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }

    /**
     * Change password for authenticated user.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        if (!Hash::check($request->current_password, $request->user()->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $request->user()->update(['password' => $request->password]);
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Password changed successfully. Please log in again.']);
    }
}
