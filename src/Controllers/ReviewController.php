<?php

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Services\ImageUploadService;

class ReviewController
{
    private ImageUploadService $images;

    public function __construct()
    {
        $this->images = new ImageUploadService();
    }

    public function index(Request $request, string $productId): Response
    {
        $product = Product::findOrFail($productId);

        return json_response([
            'reviews'       => Review::where('product_id', $product['id'])->with('consumer:id,name,avatar')->latest()->paginate(15),
            'average'       => round($product['rating'], 1),
            'total_reviews' => $product['rating_count'],
        ]);
    }

    /** Submit a review (the consumer must have received the product). */
    public function store(Request $request, string $productId): Response
    {
        $product = Product::findOrFail($productId);
        $user    = $request->user;

        $v = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'rating'   => 'required|integer|between:1,5',
            'comment'  => 'nullable|string|max:1000',
            'images'   => 'nullable|array|max:3',
            'images.*' => 'image|mimes:jpg,jpeg,png|max:3072',
        ]);

        $order = Order::query()
            ->where('id', $v['order_id'])
            ->where('consumer_id', $user['id'])
            ->where('status', 'delivered')
            ->whereRaw('EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = orders.id AND oi.product_id = ?)', [$product['id']])
            ->first();

        if (!$order) {
            throw new HttpException(422, 'You can only review products from delivered orders.');
        }

        $already = Review::where('product_id', $product['id'])->where('consumer_id', $user['id'])->where('order_id', $order['id'])->exists();
        if ($already) {
            throw new HttpException(422, 'You have already reviewed this product.');
        }

        $paths = $request->hasFile('images') ? $this->images->uploadMany($request->file('images'), 'reviews') : [];

        try {
            $review = Review::create([
                'product_id'  => $product['id'],
                'consumer_id' => $user['id'],
                'order_id'    => $order['id'],
                'rating'      => (int) $v['rating'],
                'comment'     => $v['comment'] ?? null,
                'images'      => $paths ?: null,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') { // unique(product, consumer, order) hit by a concurrent request
                throw new HttpException(422, 'You have already reviewed this product.');
            }
            throw $e;
        }

        $this->recalculateRating($product['id']);

        return json_response(['message' => 'Review submitted.', 'review' => Review::load($review, ['consumer:id,name,avatar'])], 201);
    }

    public function destroy(Request $request, string $reviewId): Response
    {
        $review = Review::findOrFail($reviewId);

        if ($review['consumer_id'] !== $request->user['id'] && !User::isAdmin($request->user)) {
            throw new HttpException(403, 'Unauthorized.');
        }

        Review::delete($review['id']);
        $this->recalculateRating($review['product_id']);

        return json_response(['message' => 'Review deleted.']);
    }

    private function recalculateRating(int $productId): void
    {
        $q = Review::where('product_id', $productId);
        Product::update($productId, [
            'rating'       => round((float) ($q->avg('rating') ?? 0), 2),
            'rating_count' => $q->count(),
        ]);
    }
}
