<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureKycApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isKycApproved()) {
            return response()->json([
                'message' => 'KYC verification required to perform this action.',
                'kyc_status' => $user?->kycVerification?->status ?? 'not_submitted',
            ], 403);
        }

        return $next($request);
    }
}
