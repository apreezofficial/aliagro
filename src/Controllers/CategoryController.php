<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Models\Category;
use App\Models\Product;
use App\Services\ImageUploadService;

class CategoryController
{
    private ImageUploadService $images;

    public function __construct()
    {
        $this->images = new ImageUploadService();
    }

    public function index(Request $request): Response
    {
        $categories = Category::query()
            ->where('is_active', 1)
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order')
            ->get();

        return json_response(['categories' => $categories]);
    }

    public function show(Request $request, string $categoryId): Response
    {
        $category = Category::findOrFail($categoryId);

        return json_response([
            'category' => Category::load($category, ['children']),
            'products' => Product::query()
                ->where('category_id', $category['id'])
                ->where('status', 'active')
                ->with('farmer:id,name')
                ->orderBy('id')
                ->paginate(20),
        ]);
    }

    // ── Admin ────────────────────────────────────────────────────────────

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string',
            'parent_id'   => 'nullable|exists:categories,id',
            'sort_order'  => 'nullable|integer',
            'icon'        => 'nullable|string',
            'image'       => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $imagePath = $request->hasFile('image') ? $this->images->upload($request->file('image'), 'categories') : null;
        unset($validated['image']);
        $validated = array_filter($validated, fn($v) => $v !== null);   // sort_order etc. fall back to DB defaults

        $category = Category::create($validated + [
            'slug'  => $this->uniqueSlug($validated['name']),
            'image' => $imagePath,
        ]);

        return json_response(['message' => 'Category created.', 'category' => $category], 201);
    }

    public function update(Request $request, string $categoryId): Response
    {
        $category  = Category::findOrFail($categoryId);
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
            'sort_order'  => 'nullable|integer',
            'icon'        => 'nullable|string',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = $this->uniqueSlug($validated['name'], $category['id']);
        }
        if (array_key_exists('sort_order', $validated) && $validated['sort_order'] === null) {
            unset($validated['sort_order']);
        }
        if (array_key_exists('is_active', $validated) && $validated['is_active'] === null) {
            unset($validated['is_active']);
        }

        return json_response(['message' => 'Category updated.', 'category' => Category::update($category['id'], $validated)]);
    }

    /** Categories are deactivated rather than deleted. */
    public function destroy(Request $request, string $categoryId): Response
    {
        $category = Category::findOrFail($categoryId);
        Category::update($category['id'], ['is_active' => false]);

        return json_response(['message' => 'Category deactivated.']);
    }

    /** slug is UNIQUE in the schema, so two "Fruits" must not produce a 500. */
    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;

        $q = Category::where('slug', $slug);
        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }
        if ($q->exists()) {
            $slug = $base . '-' . strtolower(Str::random(4));
        }

        return $slug;
    }
}
