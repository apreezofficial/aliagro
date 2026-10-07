<?php

namespace App\Services;

use App\Core\HttpException;
use App\Core\Str;
use App\Core\UploadedFile;

/**
 * Stores uploads under public/storage/{folder}/ so they are served directly by
 * the web server (no `storage:link` step). Returns the public URL path.
 */
class ImageUploadService
{
    public function upload(UploadedFile $file, string $folder = 'uploads'): string
    {
        $folder = preg_replace('/[^a-z0-9_\-]/i', '', $folder);
        // Extension comes from the sniffed MIME type, never from the client's filename.
        $filename = Str::uuid() . '.' . $file->extension();

        if (!$file->moveTo(base_path("public/storage/{$folder}/{$filename}"))) {
            throw new HttpException(500, 'Failed to store uploaded file.');
        }

        return "/storage/{$folder}/{$filename}";
    }

    /** @param UploadedFile[] $files @return string[] */
    public function uploadMany(array $files, string $folder = 'uploads'): array
    {
        return array_map(fn($file) => $this->upload($file, $folder), array_values($files));
    }

    public function delete(string $url): bool
    {
        if (!str_starts_with($url, '/storage/') || str_contains($url, '..')) {
            return false;
        }
        $path = base_path('public' . $url);
        return is_file($path) && unlink($path);
    }
}
