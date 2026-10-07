<?php

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Referral;
use App\Models\User;

class ReferralController
{
    public function index(Request $request): Response
    {
        $user = $request->user;

        if (!$user['referral_code']) {
            $user = User::update($user['id'], ['referral_code' => AuthController::newReferralCode()]);
        }

        $referrals = Referral::where('referrer_id', $user['id'])
            ->with('referred:id,name,email,created_at')
            ->latest()
            ->get();

        $count = fn(string $status) => count(array_filter($referrals, fn($r) => $r['status'] === $status));

        return json_response([
            'referral_code'   => $user['referral_code'],
            'referral_link'   => config('app.frontend_url') . '/register?ref=' . $user['referral_code'],
            'total_referrals' => count($referrals),
            'rewarded'        => $count('rewarded'),
            'pending'         => $count('pending'),
            'referrals'       => $referrals,
        ]);
    }

    /** Validate a referral code (used during registration). */
    public function validate(Request $request): Response
    {
        $v = $request->validate(['code' => 'required|string']);

        $referrer = User::where('referral_code', strtoupper($v['code']))->first();
        if (!$referrer) {
            throw new HttpException(422, 'Invalid referral code.', ['valid' => false]);
        }

        return json_response(['valid' => true, 'referrer_name' => $referrer['name']]);
    }
}
