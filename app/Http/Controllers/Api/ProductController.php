<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(private ImageUploadService $imageService) {}

    /**
     * Public: List all active products with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['farmer:id,name,avatar', 'category:id,name,slug'])
            ->where('status', 'active');

        // Filters
        if ($request->category) {
            $query->where('category_id', $request->category);
        }
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%");
            });
        }
        if ($request->min_price) {
            $query->where('price', '>=', $request->min_price);
        }
        if ($request->max_price) {
            $query->where('price', '<=', $request->max_price);
        }
        if ($request->is_organic) {
            $query->where('is_organic', true);
        }
        if ($request->farmer_id) {
            $query->where('farmer_id', $request->farmer_id);
        }
        if ($request->location) {
            $query->where('location', 'like', "%{$request->location}%");
        }

        // Sorting
        $sort = match ($request->sort) {
            'price_asc'  => ['price', 'asc'],
            'price_desc' => ['price', 'desc'],
            'rating'     => ['rating', 'desc'],
            'popular'    => ['total_sold', 'desc'],
            default      => ['created_at', 'desc'],
        };
        $query->orderBy($sort[0], $sort[1]);

        $products = $query->paginate($request->per_page ?? 20);

        return response()->json($products);
    }

    /**
     * Public: Get a single product.
     */
    public function show(Product $product): JsonResponse
    {
        if ($product->status !== 'active') {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $product->increment('views');
        $product->load(['farmer:id,name,avatar', 'category', 'reviews.consumer:id,name,avatar']);

        return response()->json(['product' => $product]);
    }

    /**
     * Farmer: Create a product.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->isFarmer()) {
            return response()->json(['message' => 'Only farmers can list products.'], 403);
        }

        $validated = $request->validate([
            'category_id'        => 'required|exists:categories,id',
            'name'               => 'required|string|max:255',
            'description'        => 'required|string',
            'price'              => 'required|numeric|min:0',
            'discount_price'     => 'nullable|numeric|min:0|lt:price',
            'unit'               => 'required|string|max:50',
            'quantity_available' => 'required|integer|min:0',
            'minimum_order'      => 'nullable|integer|min:1',
            'is_organic'         => 'boolean',
            'harvest_date'       => 'nullable|date',
            'expiry_date'        => 'nullable|date|after:harvest_date',
            'location'           => 'nullable|string|max:255',
            'images'             => 'required|array|min:1|max:8',
            'images.*'           => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $imagePaths = [];
        foreach ($request->file('images') as $image) {
            $imagePaths[] = $this->imageService->upload($image, 'products');
        }

        $product = Product::create([
            ...$validated,
            'farmer_id' => $user->id,
            'slug'      => Str::slug($validated['name']) . '-' . Str::random(6),
            'images'    => $imagePaths,
            'thumbnail' => $imagePaths[0],
            'status'    => 'active',
        ]);

        // Notify followers
        foreach ($user->followers as $follower) {
            $follower->notify(new \App\Notifications\NewFarmerProductNotification($product, $user));
        }

        return response()->json([
            'message' => 'Product listed successfully.',
            'product' => $product->load('category'),
        ], 201);
    }

    /**
     * Farmer: Update a product.
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $validated = $request->validate([
            'category_id'        => 'sometimes|exists:categories,id',
            'name'               => 'sometimes|string|max:255',
            'description'        => 'sometimes|string',
            'price'              => 'sometimes|numeric|min:0',
            'discount_price'     => 'nullable|numeric|min:0',
            'unit'               => 'sometimes|string|max:50',
            'quantity_available' => 'sometimes|integer|min:0',
            'minimum_order'      => 'nullable|integer|min:1',
            'is_organic'         => 'boolean',
            'harvest_date'       => 'nullable|date',
            'expiry_date'        => 'nullable|date',
            'location'           => 'nullable|string|max:255',
            'status'             => 'sometimes|in:active,inactive',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']) . '-' . Str::random(6);
        }

        $product->update($validated);

        return response()->json([
            'message' => 'Product updated.',
            'product' => $product->fresh()->load('category'),
        ]);
    }

    /**
     * Farmer: Add images to a product.
     */
    public function addImages(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $request->validate([
            'images'   => 'required|array|max:8',
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $existing = $product->images ?? [];
        $new      = [];

        foreach ($request->file('images') as $image) {
            $new[] = $this->imageService->upload($image, 'products');
        }

        $all = array_merge($existing, $new);
        $product->update([
            'images'    => $all,
            'thumbnail' => $all[0],
        ]);

        return response()->json(['message' => 'Images added.', 'images' => $all]);
    }

    /**
     * Farmer: Delete a product.
     */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);
        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    /**
     * Farmer: List own products.
     */
    public function myProducts(Request $request): JsonResponse
    {
        $products = Product::where('farmer_id', $request->user()->id)
            ->with('category:id,name')
            ->withTrashed()
            ->latest()
            ->paginate(20);

        return response()->json($products);
    }

    private function authorizeProduct(Request $request, Product $product): void
    {
        $user = $request->user();
        if ($product->farmer_id !== $user->id && !$user->isAdmin()) {
            abort(403, 'Unauthorized.');
        }
    }
}
