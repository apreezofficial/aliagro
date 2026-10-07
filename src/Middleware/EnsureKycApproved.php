<?php

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\KycVerification;

final class EnsureKycApproved
{
    public function handle(Request $request, callable $next): Response
    {
        $user = $request->user;
        $kyc  = $user ? KycVerification::where('user_id', $user['id'])->first() : null;

        if (!$user || ($kyc['status'] ?? null) !== 'approved') {
            throw new HttpException(403, 'KYC verification required to perform this action.', [
                'kyc_status' => $kyc['status'] ?? 'not_submitted',
            ]);
        }

        return $next($request);
    }
}
