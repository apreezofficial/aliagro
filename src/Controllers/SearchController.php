<?php

namespace App\Controllers;

use App\Core\DB;
use App\Core\ModelNotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Product;
use App\Models\ProductView;
use App\Models\User;

class SearchController
{
    private const PRODUCT_WITH = ['farmer:id,name,avatar', 'category:id,name'];

    /** Search across products and farmers. */
    public function search(Request $request): Response
    {
        $v = $request->validate(['q' => 'required|string|min:2|max:100']);
        $q = $v['q'];
        $like = "%{$q}%";

        $products = Product::query()
            ->where('status', 'active')
            ->whereRaw(
                'name LIKE ? OR description LIKE ? OR location LIKE ?
                 OR EXISTS (SELECT 1 FROM categories c WHERE c.id = products.category_id AND c.name LIKE ?)
                 OR EXISTS (SELECT 1 FROM users u WHERE u.id = products.farmer_id AND u.name LIKE ?)',
                [$like, $like, $like, $like, $like]
            )
            ->with(self::PRODUCT_WITH)
            ->orderBy('total_sold', 'desc')
            ->limit(20)
            ->get();

        $farmers = User::query()
            ->select(['id', 'name', 'avatar'])
            ->where('role', 'farmer')
            ->whereRaw(
                'name LIKE ? OR EXISTS (SELECT 1 FROM farmer_profiles fp WHERE fp.user_id = users.id
                   AND (fp.farm_name LIKE ? OR fp.state LIKE ?))',
                [$like, $like, $like]
            )
            ->with('farmer_profile:user_id,farm_name,state,rating,is_verified')
            ->limit(10)
            ->get();

        return json_response(['query' => $q, 'products' => $products, 'farmers' => $farmers]);
    }

    /** Most sold in the last 30 days. */
    public function trending(Request $request): Response
    {
        $since = gmdate('Y-m-d H:i:s', strtotime('-30 days'));   // server generated, safe to inline

        $products = Product::query()
            ->select(['products.*', "(SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                WHERE oi.product_id = products.id AND o.deleted_at IS NULL AND o.status != 'cancelled'
                AND o.created_at >= '{$since}') AS recent_sales"])
            ->where('status', 'active')
            ->with(self::PRODUCT_WITH)
            ->orderBy('recent_sales', 'desc')
            ->orderBy('total_sold', 'desc')
            ->limit(20)
            ->get();

        return json_response(['trending' => $products]);
    }

    public function recentlyViewed(Request $request): Response
    {
        $views = ProductView::where('user_id', $request->user['id'])->orderBy('last_viewed_at', 'desc')->limit(20)->get();

        $products = [];
        $ids = array_column($views, 'product_id');
        foreach ($ids ? Product::query()->whereIn('id', $ids)->where('status', 'active')->with(self::PRODUCT_WITH)->get() : [] as $p) {
            $products[$p['id']] = $p;
        }

        $out = [];
        foreach ($views as $view) {
            if (isset($products[$view['product_id']])) {
                $view['product'] = $products[$view['product_id']];
                $out[] = $view;
            }
        }

        return json_response(['recently_viewed' => $out]);
    }

    /** Based on purchase history and viewed categories; falls back to top rated. */
    public function recommended(Request $request): Response
    {
        $uid = $request->user['id'];

        $purchased = DB::select(
            'SELECT DISTINCT p.category_id FROM order_items oi
             JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id
             WHERE o.consumer_id = ? AND p.category_id IS NOT NULL', [$uid]);
        $viewed = DB::select(
            'SELECT DISTINCT p.category_id FROM product_views pv
             JOIN products p ON p.id = pv.product_id WHERE pv.user_id = ? AND p.category_id IS NOT NULL', [$uid]);
        $categoryIds = array_values(array_unique(array_merge(array_column($purchased, 'category_id'), array_column($viewed, 'category_id'))));

        $orderedIds = array_column(DB::select(
            'SELECT DISTINCT oi.product_id FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.consumer_id = ?', [$uid]
        ), 'product_id');

        $q = Product::query()->where('status', 'active')->with(self::PRODUCT_WITH);
        if ($categoryIds) {
            $q->whereIn('category_id', $categoryIds)->whereNotIn('id', $orderedIds);
        }

        return json_response(['recommended' => $q->orderBy('rating', 'desc')->orderBy('total_sold', 'desc')->limit(20)->get()]);
    }

    /** Record that the user opened a product page. */
    public function trackView(Request $request, string $productId): Response
    {
        $product = Product::where('id', (int) $productId)->where('status', 'active')->first() ?? throw new ModelNotFoundException();

        $ts = now();
        DB::statement(
            'INSERT INTO product_views (user_id, product_id, view_count, last_viewed_at, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE view_count = view_count + 1, last_viewed_at = VALUES(last_viewed_at), updated_at = VALUES(updated_at)',
            [$request->user['id'], $product['id'], $ts, $ts, $ts]
        );

        return json_response(['message' => 'View tracked.']);
    }
}
