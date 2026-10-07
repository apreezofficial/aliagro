<?php

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\ModelNotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Models\FarmerProfile;
use App\Models\Product;
use App\Models\User;
use App\Services\ImageUploadService;

class FarmerProfileController
{
    private ImageUploadService $images;

    public function __construct()
    {
        $this->images = new ImageUploadService();
    }

    public function show(Request $request): Response
    {
        $profile = FarmerProfile::where('user_id', $request->user['id'])->first();
        if (!$profile) {
            throw new HttpException(404, 'Farmer profile not found.');
        }

        return json_response(['profile' => $profile]);
    }

    public function upsert(Request $request): Response
    {
        $user = $request->user;
        if (!User::isFarmer($user)) {
            throw new HttpException(403, 'Only farmers can manage a farm profile.');
        }

        $v = $request->validate([
            'farm_name'           => 'required|string|max:255',
            'bio'                 => 'nullable|string|max:1000',
            'farm_address'        => 'required|string|max:255',
            'state'               => 'required|string|max:100',
            'lga'                 => 'nullable|string|max:100',
            'country'             => 'nullable|string|max:100',
            'latitude'            => 'nullable|numeric|between:-90,90',
            'longitude'           => 'nullable|numeric|between:-180,180',
            'farm_size'           => 'nullable|string|max:100',
            'bank_name'           => 'nullable|string|max:100',
            'bank_account_number' => 'nullable|string|max:20',
            'bank_account_name'   => 'nullable|string|max:255',
        ]);
        $v['country'] = $v['country'] ?? 'Nigeria';

        $profile = FarmerProfile::updateOrCreate(['user_id' => $user['id']], $v);

        return json_response(['message' => 'Farm profile saved.', 'profile' => $profile]);
    }

    public function uploadImages(Request $request): Response
    {
        $request->validate([
            'images'   => 'required|array|max:6',
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $profile = FarmerProfile::where('user_id', $request->user['id'])->first();
        if (!$profile) {
            throw new HttpException(404, 'Create a farm profile first.');
        }

        $all = array_merge($profile['farm_images'] ?? [], $this->images->uploadMany($request->file('images'), 'farms'));
        FarmerProfile::update($profile['id'], ['farm_images' => $all]);

        return json_response(['message' => 'Images uploaded.', 'images' => $all]);
    }

    /** Public profile by user id. */
    public function publicProfile(Request $request, string $userId): Response
    {
        $user = User::where('id', (int) $userId)->where('role', 'farmer')->first() ?? throw new ModelNotFoundException();
        $profile = FarmerProfile::where('user_id', $user['id'])->first();
        if (!$profile) {
            throw new HttpException(404, 'Farmer profile not found.');
        }

        return json_response([
            'farmer'   => ['id' => $user['id'], 'name' => $user['name'], 'avatar' => $user['avatar']],
            'profile'  => $profile,
            'products' => Product::where('farmer_id', $user['id'])->where('status', 'active')->latest()->limit(10)->get(),
        ]);
    }
}
