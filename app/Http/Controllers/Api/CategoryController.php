<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\ImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function __construct(private ImageUploadService $imageService) {}

    public function index(): JsonResponse
    {
        $categories = Category::where('is_active', true)
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['categories' => $categories]);
    }

    public function show(Category $category): JsonResponse
    {
        return response()->json([
            'category' => $category->load('children'),
            'products' => $category->products()
                ->where('status', 'active')
                ->with('farmer:id,name')
                ->paginate(20),
        ]);
    }

    // Admin
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:100',
            'description' => 'nullable|string',
            'parent_id'  => 'nullable|exists:categories,id',
            'sort_order' => 'nullable|integer',
            'icon'       => 'nullable|string',
            'image'      => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $this->imageService->upload($request->file('image'), 'categories');
        }

        $category = Category::create([
            ...$validated,
            'slug'  => Str::slug($validated['name']),
            'image' => $imagePath,
        ]);

        return response()->json(['message' => 'Category created.', 'category' => $category], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'is_active'  => 'boolean',
            'sort_order' => 'nullable|integer',
            'icon'       => 'nullable|string',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category->update($validated);

        return response()->json(['message' => 'Category updated.', 'category' => $category]);
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->update(['is_active' => false]);
        return response()->json(['message' => 'Category deactivated.']);
    }
}
