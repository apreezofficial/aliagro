<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductView;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    /**
     * Full-text search across products and farmers.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:2|max:100']);

        $q = $request->q;

        $products = Product::where('status', 'active')
            ->where(function ($query) use ($q) {
                $query->where('name', 'LIKE', "%{$q}%")
                    ->orWhere('description', 'LIKE', "%{$q}%")
                    ->orWhere('location', 'LIKE', "%{$q}%")
                    ->orWhereHas('category', fn($c) => $c->where('name', 'LIKE', "%{$q}%"))
                    ->orWhereHas('farmer', fn($f) => $f->where('name', 'LIKE', "%{$q}%"));
            })
            ->with(['farmer:id,name,avatar', 'category:id,name'])
            ->orderByDesc('total_sold')
            ->limit(20)
            ->get();

        $farmers = User::where('role', 'farmer')
            ->where(function ($query) use ($q) {
                $query->where('name', 'LIKE', "%{$q}%")
                    ->orWhereHas('farmerProfile', fn($p) =>
                        $p->where('farm_name', 'LIKE', "%{$q}%")
                          ->orWhere('state', 'LIKE', "%{$q}%")
                    );
            })
            ->with('farmerProfile:user_id,farm_name,state,rating,is_verified')
            ->limit(10)
            ->get(['id', 'name', 'avatar']);

        return response()->json([
            'query'    => $q,
            'products' => $products,
            'farmers'  => $farmers,
        ]);
    }

    /**
     * Trending products — most sold in last 30 days.
     */
    public function trending(): JsonResponse
    {
        $products = Product::where('status', 'active')
            ->withCount(['orderItems as recent_sales' => function ($q) {
                $q->whereHas('order', fn($o) =>
                    $o->where('created_at', '>=', now()->subDays(30))
                      ->where('status', '!=', 'cancelled')
                );
            }])
            ->with(['farmer:id,name,avatar', 'category:id,name'])
            ->orderByDesc('recent_sales')
            ->orderByDesc('total_sold')
            ->limit(20)
            ->get();

        return response()->json(['trending' => $products]);
    }

    /**
     * Recently viewed products for authenticated user.
     */
    public function recentlyViewed(Request $request): JsonResponse
    {
        $views = ProductView::where('user_id', $request->user()->id)
            ->with(['product' => fn($q) => $q->where('status', 'active')
                ->with('farmer:id,name,avatar', 'category:id,name')])
            ->orderByDesc('last_viewed_at')
            ->limit(20)
            ->get()
            ->filter(fn($v) => $v->product !== null)
            ->values();

        return response()->json(['recently_viewed' => $views]);
    }

    /**
     * Recommended products based on user's purchase history and viewed categories.
     */
    public function recommended(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get categories the user has ordered from
        $purchasedCategoryIds = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.consumer_id', $user->id)
            ->pluck('products.category_id')
            ->unique();

        // Get categories from recently viewed
        $viewedCategoryIds = ProductView::where('user_id', $user->id)
            ->join('products', 'products.id', '=', 'product_views.product_id')
            ->pluck('products.category_id')
            ->unique();

        $categoryIds = $purchasedCategoryIds->merge($viewedCategoryIds)->unique();

        // Products already ordered by user
        $orderedProductIds = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.consumer_id', $user->id)
            ->pluck('order_items.product_id');

        if ($categoryIds->isNotEmpty()) {
            $products = Product::where('status', 'active')
                ->whereIn('category_id', $categoryIds)
                ->whereNotIn('id', $orderedProductIds)
                ->with(['farmer:id,name,avatar', 'category:id,name'])
                ->orderByDesc('rating')
                ->orderByDesc('total_sold')
                ->limit(20)
                ->get();
        } else {
            // Fallback: top-rated products
            $products = Product::where('status', 'active')
                ->with(['farmer:id,name,avatar', 'category:id,name'])
                ->orderByDesc('rating')
                ->orderByDesc('total_sold')
                ->limit(20)
                ->get();
        }

        return response()->json(['recommended' => $products]);
    }

    /**
     * Track a product view (call when user opens a product page).
     */
    public function trackView(Request $request, int $productId): JsonResponse
    {
        $product = Product::where('id', $productId)->where('status', 'active')->firstOrFail();

        ProductView::updateOrCreate(
            ['user_id' => $request->user()->id, 'product_id' => $product->id],
            [
                'last_viewed_at' => now(),
                'view_count'     => DB::raw('view_count + 1'),
            ]
        );

        return response()->json(['message' => 'View tracked.']);
    }
}
