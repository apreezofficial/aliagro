<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private ImageUploadService $imageService) {}

    /**
     * Update user profile.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'  => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        $request->user()->update($validated);

        return response()->json([
            'message' => 'Profile updated.',
            'user'    => $request->user()->fresh(),
        ]);
    }

    /**
     * Upload profile avatar.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $path = $this->imageService->upload($request->file('avatar'), 'avatars');
        $request->user()->update(['avatar' => $path]);

        return response()->json([
            'message' => 'Avatar updated.',
            'avatar'  => $path,
        ]);
    }

    /**
     * Delete account.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);

        if ($request->user()->password && !\Hash::check($request->password, $request->user()->password)) {
            return response()->json(['message' => 'Incorrect password.'], 422);
        }

        $request->user()->tokens()->delete();
        $request->user()->delete();

        return response()->json(['message' => 'Account deleted.']);
    }
}
