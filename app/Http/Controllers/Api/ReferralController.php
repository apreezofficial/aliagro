<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReferralController extends Controller
{
    /**
     * Get current user's referral code and stats.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Generate referral code if not set
        if (!$user->referral_code) {
            $user->update(['referral_code' => strtoupper(Str::random(8))]);
        }

        $referrals = Referral::where('referrer_id', $user->id)
            ->with('referred:id,name,email,created_at')
            ->latest()
            ->get();

        return response()->json([
            'referral_code'  => $user->referral_code,
            'referral_link'  => config('app.frontend_url') . '/register?ref=' . $user->referral_code,
            'total_referrals'=> $referrals->count(),
            'rewarded'       => $referrals->where('status', 'rewarded')->count(),
            'pending'        => $referrals->where('status', 'pending')->count(),
            'referrals'      => $referrals,
        ]);
    }

    /**
     * Validate a referral code (used during registration).
     */
    public function validate(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string']);

        $referrer = \App\Models\User::where('referral_code', strtoupper($request->code))->first();

        if (!$referrer) {
            return response()->json(['valid' => false, 'message' => 'Invalid referral code.'], 422);
        }

        return response()->json([
            'valid'         => true,
            'referrer_name' => $referrer->name,
        ]);
    }
}
