<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\KycVerification;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;

class AdminController
{
    public function dashboard(Request $request): Response
    {
        return json_response([
            'stats' => [
                'total_users'     => User::query()->count(),
                'total_farmers'   => User::where('role', 'farmer')->count(),
                'total_consumers' => User::where('role', 'consumer')->count(),
                'total_products'  => Product::query()->count(),
                'total_orders'    => Order::query()->count(),
                'pending_orders'  => Order::where('status', 'pending')->count(),
                'total_revenue'   => Transaction::where('type', 'payment')->where('status', 'success')->sum('amount'),
                'pending_kyc'     => KycVerification::where('status', 'pending')->count(),
            ],
        ]);
    }

    public function users(Request $request): Response
    {
        $q = User::query()->with('kyc_verification');

        if ($request->input('role')) {
            $q->where('role', $request->input('role'));
        }
        if ($request->input('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($search = $request->input('search')) {
            $q->whereRaw('name LIKE ? OR email LIKE ?', ["%{$search}%", "%{$search}%"]);
        }

        return json_response($q->latest()->paginate(20));
    }

    /** Suspend or activate a user. */
    public function toggleUserStatus(Request $request, string $userId): Response
    {
        $user      = User::findOrFail($userId);
        $validated = $request->validate(['status' => 'required|in:active,suspended']);

        $user = User::update($user['id'], ['status' => $validated['status']]);

        // A suspended user must not keep working with tokens issued earlier.
        if ($validated['status'] === 'suspended') {
            Auth::revokeAll($user['id']);
        }

        return json_response(['message' => "User {$validated['status']}.", 'user' => $user]);
    }

    public function orders(Request $request): Response
    {
        $q = Order::query()->with(['consumer:id,name,email', 'items']);
        if ($request->input('status')) {
            $q->where('status', $request->input('status'));
        }

        return json_response($q->latest()->paginate(20));
    }

    public function updateOrderStatus(Request $request, string $orderId): Response
    {
        $order     = Order::findOrFail($orderId);
        $validated = $request->validate([
            'status' => 'required|in:confirmed,processing,shipped,delivered,cancelled,refunded',
        ]);

        return json_response([
            'message' => 'Order status updated.',
            'order'   => Order::update($order['id'], ['status' => $validated['status']]),
        ]);
    }

    /** All products, including inactive and soft-deleted. */
    public function products(Request $request): Response
    {
        $q = Product::query()->withTrashed()->with(['farmer:id,name', 'category:id,name']);
        if ($request->input('status')) {
            $q->where('status', $request->input('status'));
        }

        return json_response($q->latest()->paginate(20));
    }

    public function toggleFeatured(Request $request, string $productId): Response
    {
        $product = Product::findOrFail($productId);
        $product = Product::update($product['id'], ['is_featured' => !$product['is_featured']]);

        return json_response([
            'message'     => $product['is_featured'] ? 'Product featured.' : 'Product unfeatured.',
            'is_featured' => $product['is_featured'],
        ]);
    }

    public function transactions(Request $request): Response
    {
        $q = Transaction::query()->with(['user:id,name,email', 'order:id,order_number']);
        if ($request->input('type')) {
            $q->where('type', $request->input('type'));
        }
        if ($request->input('status')) {
            $q->where('status', $request->input('status'));
        }

        return json_response($q->latest()->paginate(20));
    }
}
