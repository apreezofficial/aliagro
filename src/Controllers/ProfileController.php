<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;
use App\Services\ImageUploadService;

class ProfileController
{
    private ImageUploadService $images;

    public function __construct()
    {
        $this->images = new ImageUploadService();
    }

    public function update(Request $request): Response
    {
        $validated = $request->validate([
            'name'  => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        $user = User::update($request->user['id'], $validated);

        return json_response(['message' => 'Profile updated.', 'user' => $user]);
    }

    public function uploadAvatar(Request $request): Response
    {
        $request->validate(['avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048']);

        $path = $this->images->upload($request->file('avatar'), 'avatars');
        User::update($request->user['id'], ['avatar' => $path]);

        return json_response(['message' => 'Avatar updated.', 'avatar' => $path]);
    }

    /** Delete account. */
    public function destroy(Request $request): Response
    {
        $request->validate(['password' => 'required|string']);

        $row = User::findWithPassword($request->user['id']);
        // OAuth-only accounts have no password and skip this check (same as before).
        if ($row['password'] && !Auth::check($request->input('password'), $row['password'])) {
            throw new HttpException(422, 'Incorrect password.');
        }

        Auth::revokeAll($row['id']);
        User::delete($row['id']);

        return json_response(['message' => 'Account deleted.']);
    }
}
