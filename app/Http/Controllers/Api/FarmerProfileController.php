<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FarmerProfile;
use App\Models\User;
use App\Services\ImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FarmerProfileController extends Controller
{
    public function __construct(private ImageUploadService $imageService) {}

    /**
     * Get authenticated farmer's profile.
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->farmerProfile;

        if (!$profile) {
            return response()->json(['message' => 'Farmer profile not found.'], 404);
        }

        return response()->json(['profile' => $profile]);
    }

    /**
     * Create or update farmer profile.
     */
    public function upsert(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->isFarmer()) {
            return response()->json(['message' => 'Only farmers can manage a farm profile.'], 403);
        }

        $validated = $request->validate([
            'farm_name'    => 'required|string|max:255',
            'bio'          => 'nullable|string|max:1000',
            'farm_address' => 'required|string|max:255',
            'state'        => 'required|string|max:100',
            'lga'          => 'nullable|string|max:100',
            'country'      => 'nullable|string|max:100',
            'latitude'     => 'nullable|numeric|between:-90,90',
            'longitude'    => 'nullable|numeric|between:-180,180',
            'farm_size'    => 'nullable|string|max:100',
            'bank_name'    => 'nullable|string|max:100',
            'bank_account_number' => 'nullable|string|max:20',
            'bank_account_name'   => 'nullable|string|max:255',
        ]);

        $profile = FarmerProfile::updateOrCreate(
            ['user_id' => $user->id],
            array_merge($validated, ['country' => $validated['country'] ?? 'Nigeria'])
        );

        return response()->json([
            'message' => 'Farm profile saved.',
            'profile' => $profile,
        ]);
    }

    /**
     * Upload farm images.
     */
    public function uploadImages(Request $request): JsonResponse
    {
        $request->validate([
            'images'   => 'required|array|max:6',
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $user    = $request->user();
        $profile = $user->farmerProfile;

        if (!$profile) {
            return response()->json(['message' => 'Create a farm profile first.'], 404);
        }

        $existing = $profile->farm_images ?? [];
        $new      = [];

        foreach ($request->file('images') as $image) {
            $new[] = $this->imageService->upload($image, 'farms');
        }

        $all = array_merge($existing, $new);
        $profile->update(['farm_images' => $all]);

        return response()->json([
            'message' => 'Images uploaded.',
            'images'  => $all,
        ]);
    }

    /**
     * Public: Get a farmer's profile by user ID.
     */
    public function publicProfile(int $userId): JsonResponse
    {
        $user = User::where('id', $userId)->where('role', 'farmer')->firstOrFail();
        $profile = $user->farmerProfile;

        if (!$profile) {
            return response()->json(['message' => 'Farmer profile not found.'], 404);
        }

        return response()->json([
            'farmer'   => [
                'id'     => $user->id,
                'name'   => $user->name,
                'avatar' => $user->avatar,
            ],
            'profile'  => $profile,
            'products' => $user->products()->where('status', 'active')->latest()->take(10)->get(),
        ]);
    }
}
