<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Wishlist;

class WishlistController
{
    public function index(Request $request): Response
    {
        return json_response(
            Wishlist::where('user_id', $request->user['id'])
                ->with('product:id,name,price,discount_price,thumbnail,status,unit')
                ->latest()
                ->paginate(20)
        );
    }

    public function toggle(Request $request): Response
    {
        $v = $request->validate(['product_id' => 'required|exists:products,id']);

        $existing = Wishlist::where('user_id', $request->user['id'])->where('product_id', $v['product_id'])->first();
        if ($existing) {
            Wishlist::delete($existing['id']);
            return json_response(['message' => 'Removed from wishlist.', 'wishlisted' => false]);
        }

        Wishlist::create(['user_id' => $request->user['id'], 'product_id' => $v['product_id']]);

        return json_response(['message' => 'Added to wishlist.', 'wishlisted' => true]);
    }
}
