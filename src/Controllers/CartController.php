<?php

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;

class CartController
{
    private function cartFor(Request $request): array
    {
        return Cart::firstOrCreate(['user_id' => $request->user['id']]);
    }

    public function index(Request $request): Response
    {
        $cart = Cart::load($this->cartFor($request), ['items.product.farmer:id,name']);

        $items = [];
        foreach ($cart['items'] as $item) {
            if (!$item['product']) {
                continue; // product was deleted
            }
            $items[] = [
                'id'       => $item['id'],
                'product'  => $item['product'],
                'quantity' => $item['quantity'],
                'subtotal' => Product::effectivePrice($item['product']) * $item['quantity'],
            ];
        }

        return json_response(['cart' => [
            'id'    => $cart['id'],
            'items' => $items,
            'total' => array_sum(array_column($items, 'subtotal')),
            'count' => count($items),
        ]]);
    }

    public function add(Request $request): Response
    {
        $v = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1',
        ]);

        $product = Product::findOrFail($v['product_id']);
        if (!Product::isInStock($product)) {
            throw new HttpException(422, 'Product is out of stock.');
        }

        $cart = $this->cartFor($request);
        $item = CartItem::where('cart_id', $cart['id'])->where('product_id', $product['id'])->first();

        if ($item) {
            $newQty = $item['quantity'] + (int) $v['quantity'];
            if ($newQty > $product['quantity_available']) {
                throw new HttpException(422, 'Not enough stock available.');
            }
            CartItem::update($item['id'], ['quantity' => $newQty]);
        } else {
            CartItem::create(['cart_id' => $cart['id'], 'product_id' => $product['id'], 'quantity' => (int) $v['quantity']]);
        }

        return json_response(['message' => 'Item added to cart.']);
    }

    public function update(Request $request, string $cartItemId): Response
    {
        $item = $this->ownedItem($request, $cartItemId);
        $v = $request->validate(['quantity' => 'required|integer|min:1']);

        $product = Product::findOrFail($item['product_id']);
        if ($v['quantity'] > $product['quantity_available']) {
            throw new HttpException(422, 'Not enough stock available.');
        }

        CartItem::update($item['id'], ['quantity' => (int) $v['quantity']]);

        return json_response(['message' => 'Cart updated.']);
    }

    public function remove(Request $request, string $cartItemId): Response
    {
        $item = $this->ownedItem($request, $cartItemId);
        CartItem::delete($item['id']);

        return json_response(['message' => 'Item removed from cart.']);
    }

    public function clear(Request $request): Response
    {
        $cart = Cart::where('user_id', $request->user['id'])->first();
        if ($cart) {
            CartItem::where('cart_id', $cart['id'])->delete();
        }

        return json_response(['message' => 'Cart cleared.']);
    }

    private function ownedItem(Request $request, string $id): array
    {
        $item = CartItem::findOrFail($id);
        $cart = Cart::find($item['cart_id']);
        if (!$cart || $cart['user_id'] !== $request->user['id']) {
            throw new HttpException(403, 'Unauthorized.');
        }
        return $item;
    }
}
