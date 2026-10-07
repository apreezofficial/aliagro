<?php

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Models\Product;
use App\Models\User;
use App\Notifications\NewFarmerProductNotification;
use App\Services\ImageUploadService;

class ProductController
{
    private ImageUploadService $images;

    public function __construct()
    {
        $this->images = new ImageUploadService();
    }

    /** Public: list active products with filters. */
    public function index(Request $request): Response
    {
        $q = Product::query()
            ->with(['farmer:id,name,avatar', 'category:id,name,slug'])
            ->where('status', 'active');

        if ($request->input('category')) {
            $q->where('category_id', $request->input('category'));
        }
        if ($search = $request->input('search')) {
            $q->whereRaw('name LIKE ? OR description LIKE ?', ["%{$search}%", "%{$search}%"]);
        }
        if ($request->input('min_price')) {
            $q->where('price', '>=', $request->input('min_price'));
        }
        if ($request->input('max_price')) {
            $q->where('price', '<=', $request->input('max_price'));
        }
        if ($request->input('is_organic')) {
            $q->where('is_organic', 1);
        }
        if ($request->input('farmer_id')) {
            $q->where('farmer_id', $request->input('farmer_id'));
        }
        if ($location = $request->input('location')) {
            $q->where('location', 'like', "%{$location}%");
        }

        [$column, $direction] = match ($request->input('sort')) {
            'price_asc'  => ['price', 'asc'],
            'price_desc' => ['price', 'desc'],
            'rating'     => ['rating', 'desc'],
            'popular'    => ['total_sold', 'desc'],
            default      => ['created_at', 'desc'],
        };
        $q->orderBy($column, $direction)->orderBy('id', 'desc');

        $perPage = min(100, max(1, (int) ($request->input('per_page') ?? 20)));

        return json_response($q->paginate($perPage));
    }

    /** Public: a single active product. */
    public function show(Request $request, string $productId): Response
    {
        $product = Product::findOrFail($productId);

        if ($product['status'] !== 'active') {
            throw new HttpException(404, 'Product not found.');
        }

        Product::increment($product['id'], 'views');
        $product['views']++;
        $product = Product::load($product, ['farmer:id,name,avatar', 'category', 'reviews.consumer:id,name,avatar']);

        return json_response(['product' => $product]);
    }

    /** Farmer: create a product. */
    public function store(Request $request): Response
    {
        $user = $request->user;

        if (!User::isFarmer($user)) {
            throw new HttpException(403, 'Only farmers can list products.');
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

        $paths = $this->images->uploadMany($request->file('images'), 'products');

        unset($validated['images']);
        $validated = array_filter($validated, fn($v) => $v !== null);   // let DB defaults apply (e.g. minimum_order)

        $product = Product::create($validated + [
            'farmer_id' => $user['id'],
            'slug'      => Str::slug($validated['name']) . '-' . Str::random(6),
            'images'    => $paths,
            'thumbnail' => $paths[0],
            'status'    => 'active',
        ]);

        // Notify followers
        $followers = \App\Core\DB::select(
            'SELECT u.* FROM users u JOIN farmer_follows f ON f.follower_id = u.id WHERE f.farmer_id = ?',
            [$user['id']]
        );
        foreach ($followers as $follower) {
            (new NewFarmerProductNotification($product, $user))->send(User::hydrate($follower));
        }

        return json_response([
            'message' => 'Product listed successfully.',
            'product' => Product::load($product, ['category']),
        ], 201);
    }

    /** Farmer: update a product. */
    public function update(Request $request, string $productId): Response
    {
        $product = Product::findOrFail($productId);
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
        // minimum_order is NOT NULL in the schema; an explicit null means "leave as is".
        if (array_key_exists('minimum_order', $validated) && $validated['minimum_order'] === null) {
            unset($validated['minimum_order']);
        }

        $product = Product::update($product['id'], $validated);

        return json_response(['message' => 'Product updated.', 'product' => Product::load($product, ['category'])]);
    }

    /** Farmer: add images to a product. */
    public function addImages(Request $request, string $productId): Response
    {
        $product = Product::findOrFail($productId);
        $this->authorizeProduct($request, $product);

        $request->validate([
            'images'   => 'required|array|max:8',
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $all = array_merge($product['images'] ?? [], $this->images->uploadMany($request->file('images'), 'products'));
        Product::update($product['id'], ['images' => $all, 'thumbnail' => $all[0]]);

        return json_response(['message' => 'Images added.', 'images' => $all]);
    }

    public function destroy(Request $request, string $productId): Response
    {
        $product = Product::findOrFail($productId);
        $this->authorizeProduct($request, $product);
        Product::delete($product['id']);

        return json_response(['message' => 'Product deleted.']);
    }

    /** Farmer: own products (including soft-deleted). */
    public function myProducts(Request $request): Response
    {
        return json_response(
            Product::query()->withTrashed()
                ->where('farmer_id', $request->user['id'])
                ->with('category:id,name')
                ->latest()
                ->paginate(20)
        );
    }

    private function authorizeProduct(Request $request, array $product): void
    {
        $user = $request->user;
        if ($product['farmer_id'] !== $user['id'] && !User::isAdmin($user)) {
            throw new HttpException(403, 'Unauthorized.');
        }
    }
}
