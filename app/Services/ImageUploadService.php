<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageUploadService
{
    /**
     * Upload an image to the given folder and return the stored path.
     */
    public function upload(UploadedFile $file, string $folder = 'uploads'): string
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path     = $file->storeAs("public/{$folder}", $filename);

        // Return the public URL path
        return Storage::url($path);
    }

    /**
     * Delete an image by its stored URL path.
     */
    public function delete(string $url): bool
    {
        // Convert URL back to storage path
        $path = str_replace('/storage/', 'public/', $url);
        return Storage::delete($path);
    }

    /**
     * Upload multiple images and return array of paths.
     */
    public function uploadMany(array $files, string $folder = 'uploads'): array
    {
        return array_map(fn($file) => $this->upload($file, $folder), $files);
    }
}
