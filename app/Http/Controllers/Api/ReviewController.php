<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Services\ImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(private ImageUploadService $imageService) {}

    /**
     * Get reviews for a product.
     */
    public function index(Product $product): JsonResponse
    {
        $reviews = $product->reviews()
            ->with('consumer:id,name,avatar')
            ->latest()
            ->paginate(15);

        return response()->json([
            'reviews'      => $reviews,
            'average'      => round($product->rating, 1),
            'total_reviews' => $product->rating_count,
        ]);
    }

    /**
     * Submit a review (consumer must have purchased the product).
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'rating'   => 'required|integer|between:1,5',
            'comment'  => 'nullable|string|max:1000',
            'images'   => 'nullable|array|max:3',
            'images.*' => 'image|mimes:jpg,jpeg,png|max:3072',
        ]);

        // Verify purchase
        $order = Order::where('id', $request->order_id)
            ->where('consumer_id', $user->id)
            ->where('status', 'delivered')
            ->whereHas('items', fn($q) => $q->where('product_id', $product->id))
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'You can only review products from delivered orders.',
            ], 422);
        }

        if (Review::where('product_id', $product->id)
            ->where('consumer_id', $user->id)
            ->where('order_id', $order->id)
            ->exists()) {
            return response()->json(['message' => 'You have already reviewed this product.'], 422);
        }

        $imagePaths = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $imagePaths[] = $this->imageService->upload($image, 'reviews');
            }
        }

        $review = Review::create([
            'product_id'  => $product->id,
            'consumer_id' => $user->id,
            'order_id'    => $order->id,
            'rating'      => $request->rating,
            'comment'     => $request->comment,
            'images'      => $imagePaths ?: null,
        ]);

        // Update product rating
        $avg = Review::where('product_id', $product->id)->avg('rating');
        $cnt = Review::where('product_id', $product->id)->count();
        $product->update(['rating' => round($avg, 2), 'rating_count' => $cnt]);

        return response()->json([
            'message' => 'Review submitted.',
            'review'  => $review->load('consumer:id,name,avatar'),
        ], 201);
    }

    /**
     * Delete own review.
     */
    public function destroy(Request $request, Review $review): JsonResponse
    {
        if ($review->consumer_id !== $request->user()->id && !$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $product = $review->product;
        $review->delete();

        // Recalculate rating
        $avg = Review::where('product_id', $product->id)->avg('rating') ?? 0;
        $cnt = Review::where('product_id', $product->id)->count();
        $product->update(['rating' => round($avg, 2), 'rating_count' => $cnt]);

        return response()->json(['message' => 'Review deleted.']);
    }
}
