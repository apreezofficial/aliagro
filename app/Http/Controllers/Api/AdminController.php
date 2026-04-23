<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /**
     * Dashboard stats.
     */
    public function dashboard(): JsonResponse
    {
        return response()->json([
            'stats' => [
                'total_users'     => User::count(),
                'total_farmers'   => User::where('role', 'farmer')->count(),
                'total_consumers' => User::where('role', 'consumer')->count(),
                'total_products'  => Product::count(),
                'total_orders'    => Order::count(),
                'pending_orders'  => Order::where('status', 'pending')->count(),
                'total_revenue'   => Transaction::where('type', 'payment')->where('status', 'success')->sum('amount'),
                'pending_kyc'     => \App\Models\KycVerification::where('status', 'pending')->count(),
            ],
        ]);
    }

    /**
     * List all users.
     */
    public function users(Request $request): JsonResponse
    {
        $users = User::with('kycVerification')
            ->when($request->role, fn($q) => $q->where('role', $request->role))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->search, fn($q) => $q->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            }))
            ->latest()
            ->paginate(20);

        return response()->json($users);
    }

    /**
     * Suspend or activate a user.
     */
    public function toggleUserStatus(Request $request, User $user): JsonResponse
    {
        $request->validate(['status' => 'required|in:active,suspended']);

        $user->update(['status' => $request->status]);

        return response()->json([
            'message' => "User {$request->status}.",
            'user'    => $user,
        ]);
    }

    /**
     * List all orders.
     */
    public function orders(Request $request): JsonResponse
    {
        $orders = Order::with('consumer:id,name,email', 'items')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return response()->json($orders);
    }

    /**
     * Update order status.
     */
    public function updateOrderStatus(Request $request, Order $order): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:confirmed,processing,shipped,delivered,cancelled,refunded',
        ]);

        $order->update(['status' => $request->status]);

        return response()->json(['message' => 'Order status updated.', 'order' => $order]);
    }

    /**
     * List all products (including inactive).
     */
    public function products(Request $request): JsonResponse
    {
        $products = Product::with('farmer:id,name', 'category:id,name')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->withTrashed()
            ->latest()
            ->paginate(20);

        return response()->json($products);
    }

    /**
     * Feature/unfeature a product.
     */
    public function toggleFeatured(Product $product): JsonResponse
    {
        $product->update(['is_featured' => !$product->is_featured]);

        return response()->json([
            'message'     => $product->is_featured ? 'Product featured.' : 'Product unfeatured.',
            'is_featured' => $product->is_featured,
        ]);
    }

    /**
     * Transactions list.
     */
    public function transactions(Request $request): JsonResponse
    {
        $transactions = Transaction::with('user:id,name,email', 'order:id,order_number')
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return response()->json($transactions);
    }
}
