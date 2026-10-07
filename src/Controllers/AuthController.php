<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Models\LoginActivity;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;

class AuthController
{
    /** Register a new user (consumer or farmer). */
    public function register(Request $request): Response
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'email'         => 'required|email|unique:users,email',
            'phone'         => 'nullable|string|max:20',
            'password'      => 'required|confirmed|strong_password',
            'role'          => 'required|in:consumer,farmer',
            'referral_code' => 'nullable|string|exists:users,referral_code',
        ]);

        $user = User::create([
            'name'          => $validated['name'],
            'email'         => $validated['email'],
            'phone'         => $validated['phone'] ?? null,
            'password'      => Auth::hash($validated['password']),
            'role'          => $validated['role'],
            'referral_code' => self::newReferralCode(),
        ]);

        if (!empty($validated['referral_code'])) {
            $referrer = User::where('referral_code', $validated['referral_code'])->first();
            if ($referrer) {
                Referral::create([
                    'referrer_id' => $referrer['id'],
                    'referred_id' => $user['id'],
                    'status'      => 'pending',
                ]);
            }
        }

        (new VerifyEmailNotification())->send($user);

        return json_response([
            'message' => 'Registration successful. Please verify your email.',
            'user'    => $user,
            'token'   => Auth::createToken($user['id']),
        ], 201);
    }

    /** Login with email and password. */
    public function login(Request $request): Response
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $row = User::findByEmailWithPassword($validated['email']);

        if (!$row || !Auth::check($validated['password'], $row['password'])) {
            if ($row) {
                $this->logActivity($request, (int) $row['id'], 'failed');
            }
            throw new HttpException(401, 'Invalid credentials.');
        }

        if ($row['status'] === 'suspended') {
            throw new HttpException(403, 'Your account has been suspended.');
        }

        $userId = (int) $row['id'];
        Auth::revokeAll($userId);
        $token = Auth::createToken($userId);

        $this->logActivity($request, $userId, 'success');

        return json_response([
            'message' => 'Login successful.',
            'user'    => User::load(User::findOrFail($userId), ['farmer_profile', 'kyc_verification']),
            'token'   => $token,
        ]);
    }

    public function logout(Request $request): Response
    {
        Auth::revokeCurrent($request->token);

        return json_response(['message' => 'Logged out successfully.']);
    }

    /** Authenticated user + profile + KYC. */
    public function me(Request $request): Response
    {
        return json_response([
            'user' => User::load($request->user, ['farmer_profile', 'kyc_verification']),
        ]);
    }

    public function sendVerificationEmail(Request $request): Response
    {
        if (User::hasVerifiedEmail($request->user)) {
            return json_response(['message' => 'Email already verified.']);
        }

        (new VerifyEmailNotification())->send($request->user);

        return json_response(['message' => 'Verification email sent.']);
    }

    /** Public link target of the verification email (signed, 60 minutes). */
    public function verifyEmail(Request $request, string $id, string $hash): Response
    {
        $user = User::findOrFail($id);

        if (!hash_equals(sha1($user['email']), $hash)) {
            throw new HttpException(400, 'Invalid verification link.');
        }

        $signedUrl = config('app.url') . $request->path . '?' . http_build_query($request->query);
        if (!Auth::hasValidSignature($signedUrl)) {
            throw new HttpException(403, 'Invalid signature.');
        }

        if (User::hasVerifiedEmail($user)) {
            return json_response(['message' => 'Email already verified.']);
        }

        User::update($user['id'], ['email_verified_at' => now()]);

        return json_response(['message' => 'Email verified successfully.']);
    }

    /** Send password reset link. */
    public function forgotPassword(Request $request): Response
    {
        $validated = $request->validate(['email' => 'required|email']);

        $user = User::where('email', $validated['email'])->first();
        if (!$user) {
            throw new HttpException(400, "We can't find a user with that email address.");
        }

        $existing = DB::first('SELECT created_at FROM password_reset_tokens WHERE email = ?', [$user['email']]);
        if ($existing && $existing['created_at'] && strtotime($existing['created_at'] . ' UTC') > time() - 60) {
            throw new HttpException(400, 'Please wait before retrying.');
        }

        $token = bin2hex(random_bytes(32));
        DB::transaction(function () use ($user, $token) {
            DB::delete('password_reset_tokens', 'email = ?', [$user['email']]);
            DB::insert('password_reset_tokens', [
                'email'      => $user['email'],
                'token'      => Auth::hash($token),
                'created_at' => now(),
            ]);
        });

        (new ResetPasswordNotification($token))->send($user);

        return json_response(['message' => 'Password reset link sent to your email.']);
    }

    public function resetPassword(Request $request): Response
    {
        $validated = $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => 'required|confirmed|strong_password',
        ]);

        $user = User::where('email', $validated['email'])->first();
        if (!$user) {
            throw new HttpException(400, "We can't find a user with that email address.");
        }

        $record = DB::first('SELECT * FROM password_reset_tokens WHERE email = ?', [$user['email']]);
        $expired = !$record || !$record['created_at'] || strtotime($record['created_at'] . ' UTC') < time() - 3600;
        if ($expired || !Auth::check((string) $validated['token'], $record['token'])) {
            throw new HttpException(400, 'This password reset token is invalid.');
        }

        DB::transaction(function () use ($user, $validated) {
            User::update($user['id'], ['password' => Auth::hash($validated['password'])]);
            Auth::revokeAll($user['id']);
            DB::delete('password_reset_tokens', 'email = ?', [$user['email']]);
        });

        return json_response(['message' => 'Password reset successfully.']);
    }

    /** Change password for the authenticated user. */
    public function changePassword(Request $request): Response
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|confirmed|strong_password',
        ]);

        $row = User::findWithPassword($request->user['id']);
        if (!Auth::check($validated['current_password'], $row['password'])) {
            throw new HttpException(422, 'Current password is incorrect.');
        }

        User::update($row['id'], ['password' => Auth::hash($validated['password'])]);
        Auth::revokeAll($row['id']);

        return json_response(['message' => 'Password changed successfully. Please log in again.']);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    public static function newReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (User::where('referral_code', $code)->exists());

        return $code;
    }

    private function logActivity(Request $request, int $userId, string $status): void
    {
        $agent = $request->userAgent() ?? '';

        LoginActivity::create([
            'user_id'      => $userId,
            'ip_address'   => $request->ip(),
            'user_agent'   => substr($agent, 0, 255),
            'device'       => $this->detect($agent, ['mobile' => 'Mobile', 'tablet' => 'Tablet'], 'Desktop'),
            'browser'      => $this->detect($agent, array_combine(
                ['Chrome', 'Firefox', 'Safari', 'Edge', 'Opera', 'MSIE'],
                ['Chrome', 'Firefox', 'Safari', 'Edge', 'Opera', 'MSIE']
            ), 'Unknown'),
            'platform'     => $this->detect($agent, array_combine(
                ['Windows', 'Mac', 'Linux', 'Android', 'iOS', 'iPhone', 'iPad'],
                ['Windows', 'Mac', 'Linux', 'Android', 'iOS', 'iPhone', 'iPad']
            ), 'Unknown'),
            'status'       => $status,
            'logged_in_at' => now(),
        ]);
    }

    /** First needle (case-insensitive) found in the user agent wins. */
    private function detect(string $agent, array $needles, string $default): string
    {
        foreach ($needles as $needle => $label) {
            if (stripos($agent, $needle) !== false) {
                return $label;
            }
        }
        return $default;
    }
}
