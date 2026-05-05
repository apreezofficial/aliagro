<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GdprController extends Controller
{
    /**
     * Export all user data as JSON (GDPR data portability).
     */
    public function export(Request $request): JsonResponse
    {
        $user = $request->user()->load([
            'farmerProfile',
            'kycVerification',
            'orders.items',
            'reviews',
            'deliveryAddresses',
            'wallet.transactions',
            'loyaltyPoints',
            'loginActivities',
            'badges',
        ]);

        $data = [
            'exported_at'       => now()->toIso8601String(),
            'profile'           => [
                'id'                => $user->id,
                'name'              => $user->name,
                'email'             => $user->email,
                'phone'             => $user->phone,
                'role'              => $user->role,
                'referral_code'     => $user->referral_code,
                'email_verified_at' => $user->email_verified_at,
                'created_at'        => $user->created_at,
            ],
            'farmer_profile'    => $user->farmerProfile,
            'kyc'               => $user->kycVerification ? [
                'status'   => $user->kycVerification->status,
                'id_type'  => $user->kycVerification->id_type,
                'state'    => $user->kycVerification->state,
                'country'  => $user->kycVerification->country,
            ] : null,
            'orders'            => $user->orders->map(fn($o) => [
                'order_number'   => $o->order_number,
                'total'          => $o->total,
                'status'         => $o->status,
                'created_at'     => $o->created_at,
                'items'          => $o->items->map(fn($i) => [
                    'product'  => $i->product_name,
                    'quantity' => $i->quantity,
                    'price'    => $i->unit_price,
                ]),
            ]),
            'reviews'           => $user->reviews->map(fn($r) => [
                'product_id' => $r->product_id,
                'rating'     => $r->rating,
                'comment'    => $r->comment,
                'created_at' => $r->created_at,
            ]),
            'delivery_addresses'=> $user->deliveryAddresses,
            'wallet'            => $user->wallet ? [
                'balance'      => $user->wallet->balance,
                'transactions' => $user->wallet->transactions->map(fn($t) => [
                    'amount'      => $t->amount,
                    'type'        => $t->type,
                    'category'    => $t->category,
                    'description' => $t->description,
                    'created_at'  => $t->created_at,
                ]),
            ] : null,
            'loyalty_points'    => $user->loyaltyPoints?->balance ?? 0,
            'badges'            => $user->badges->pluck('name'),
            'login_history'     => $user->loginActivities->map(fn($l) => [
                'ip'         => $l->ip_address,
                'device'     => $l->device,
                'browser'    => $l->browser,
                'status'     => $l->status,
                'logged_in'  => $l->logged_in_at,
            ]),
        ];

        // Mark as requested
        $user->update([
            'gdpr_data_requested' => true,
            'gdpr_requested_at'   => now(),
        ]);

        return response()->json([
            'message' => 'Your data export is ready.',
            'data'    => $data,
        ]);
    }

    /**
     * Request account deletion (GDPR right to erasure).
     */
    public function requestDeletion(Request $request): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        $user = $request->user();

        // Anonymize instead of hard delete to preserve order history integrity
        $user->update([
            'name'        => 'Deleted User',
            'email'       => 'deleted_' . $user->id . '@aliagro.com',
            'phone'       => null,
            'avatar'      => null,
            'google_id'   => null,
            'google_token'=> null,
            'status'      => 'suspended',
        ]);

        // Revoke all tokens
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Your account has been anonymized and access revoked. Order history is retained for legal compliance.',
        ]);
    }
}
