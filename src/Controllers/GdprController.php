<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;

class GdprController
{
    /** Export all user data (data portability). */
    public function export(Request $request): Response
    {
        $user = User::load($request->user, [
            'farmer_profile', 'kyc_verification', 'orders.items', 'reviews', 'delivery_addresses',
            'wallet.transactions', 'loyalty_points', 'login_activities', 'badges',
        ]);

        $kyc    = $user['kyc_verification'];
        $wallet = $user['wallet'];

        $data = [
            'exported_at' => gmdate('c'),
            'profile'     => [
                'id'                => $user['id'],
                'name'              => $user['name'],
                'email'             => $user['email'],
                'phone'             => $user['phone'],
                'role'              => $user['role'],
                'referral_code'     => $user['referral_code'],
                'email_verified_at' => $user['email_verified_at'],
                'created_at'        => $user['created_at'],
            ],
            'farmer_profile' => $user['farmer_profile'],
            'kyc'            => $kyc ? [
                'status'  => $kyc['status'],
                'id_type' => $kyc['id_type'],
                'state'   => $kyc['state'],
                'country' => $kyc['country'],
            ] : null,
            'orders' => array_map(fn($o) => [
                'order_number' => $o['order_number'],
                'total'        => $o['total'],
                'status'       => $o['status'],
                'created_at'   => $o['created_at'],
                'items'        => array_map(fn($i) => [
                    'product'  => $i['product_name'],
                    'quantity' => $i['quantity'],
                    'price'    => $i['unit_price'],
                ], $o['items']),
            ], $user['orders']),
            'reviews' => array_map(fn($r) => [
                'product_id' => $r['product_id'],
                'rating'     => $r['rating'],
                'comment'    => $r['comment'],
                'created_at' => $r['created_at'],
            ], $user['reviews']),
            'delivery_addresses' => $user['delivery_addresses'],
            'wallet'             => $wallet ? [
                'balance'      => $wallet['balance'],
                'transactions' => array_map(fn($t) => [
                    'amount'      => $t['amount'],
                    'type'        => $t['type'],
                    'category'    => $t['category'],
                    'description' => $t['description'],
                    'created_at'  => $t['created_at'],
                ], $wallet['transactions']),
            ] : null,
            'loyalty_points' => $user['loyalty_points']['balance'] ?? 0,
            'badges'         => array_column($user['badges'], 'name'),
            'login_history'  => array_map(fn($l) => [
                'ip'        => $l['ip_address'],
                'device'    => $l['device'],
                'browser'   => $l['browser'],
                'status'    => $l['status'],
                'logged_in' => $l['logged_in_at'],
            ], $user['login_activities']),
        ];

        User::update($user['id'], ['gdpr_data_requested' => true, 'gdpr_requested_at' => now()]);

        return json_response(['message' => 'Your data export is ready.', 'data' => $data]);
    }

    /** Right to erasure: anonymise instead of hard delete to keep order history intact. */
    public function requestDeletion(Request $request): Response
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        $user = $request->user;
        User::update($user['id'], [
            'name'         => 'Deleted User',
            'email'        => 'deleted_' . $user['id'] . '@aliagro.com',
            'phone'        => null,
            'avatar'       => null,
            'google_id'    => null,
            'google_token' => null,
            'status'       => 'suspended',
        ]);

        Auth::revokeAll($user['id']);

        return json_response([
            'message' => 'Your account has been anonymized and access revoked. Order history is retained for legal compliance.',
        ]);
    }
}
