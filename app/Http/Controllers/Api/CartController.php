<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    private function getOrCreateCart(Request $request): Cart
    {
        return Cart::firstOrCreate(['user_id' => $request->user()->id]);
    }

    /**
     * Get cart contents.
     */
    public function index(Request $request): JsonResponse
    {
        $cart = $this->getOrCreateCart($request);
        $cart->load('items.product.farmer:id,name');

        $items = $cart->items->map(function ($item) {
            return [
                'id'       => $item->id,
                'product'  => $item->product,
                'quantity' => $item->quantity,
                'subtotal' => $item->product->effective_price * $item->quantity,
            ];
        });

        return response()->json([
            'cart'  => [
                'id'    => $cart->id,
                'items' => $items,
                'total' => $items->sum('subtotal'),
                'count' => $items->count(),
            ],
        ]);
    }

    /**
     * Add item to cart.
     */
    public function add(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1',
        ]);

        $product = Product::findOrFail($request->product_id);

        if (!$product->isInStock()) {
            return response()->json(['message' => 'Product is out of stock.'], 422);
        }

        $cart = $this->getOrCreateCart($request);

        $item = CartItem::where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->first();

        if ($item) {
            $newQty = $item->quantity + $request->quantity;
            if ($newQty > $product->quantity_available) {
                return response()->json(['message' => 'Not enough stock available.'], 422);
            }
            $item->update(['quantity' => $newQty]);
        } else {
            CartItem::create([
                'cart_id'    => $cart->id,
                'product_id' => $product->id,
                'quantity'   => $request->quantity,
            ]);
        }

        return response()->json(['message' => 'Item added to cart.']);
    }

    /**
     * Update cart item quantity.
     */
    public function update(Request $request, CartItem $cartItem): JsonResponse
    {
        $this->authorizeCartItem($request, $cartItem);

        $request->validate(['quantity' => 'required|integer|min:1']);

        if ($request->quantity > $cartItem->product->quantity_available) {
            return response()->json(['message' => 'Not enough stock available.'], 422);
        }

        $cartItem->update(['quantity' => $request->quantity]);

        return response()->json(['message' => 'Cart updated.']);
    }

    /**
     * Remove item from cart.
     */
    public function remove(Request $request, CartItem $cartItem): JsonResponse
    {
        $this->authorizeCartItem($request, $cartItem);
        $cartItem->delete();

        return response()->json(['message' => 'Item removed from cart.']);
    }

    /**
     * Clear entire cart.
     */
    public function clear(Request $request): JsonResponse
    {
        $cart = $request->user()->cart;
        $cart?->items()->delete();

        return response()->json(['message' => 'Cart cleared.']);
    }

    private function authorizeCartItem(Request $request, CartItem $item): void
    {
        if ($item->cart->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized.');
        }
    }
}
