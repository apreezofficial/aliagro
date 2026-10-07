<?php

namespace App\Core;

/** Thin wrapper over one $_FILES entry. */
final class UploadedFile
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png'  => ['png'],
        'image/webp' => ['webp'],
        'image/gif'  => ['gif'],
        'image/bmp'  => ['bmp'],
    ];

    public function __construct(
        public readonly string $name,
        public readonly string $tmpName,
        public readonly int $size,
        public readonly int $error,
    ) {}

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && $this->tmpName !== '' && is_uploaded_file($this->tmpName);
    }

    public function sizeInKb(): float
    {
        return $this->size / 1024;
    }

    /** MIME type sniffed from the file contents (never trusts the client). */
    public function mimeType(): ?string
    {
        if (!is_file($this->tmpName)) {
            return null;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return $finfo->file($this->tmpName) ?: null;
    }

    /** Extensions that are acceptable for the sniffed MIME type. */
    public function guessedExtensions(): array
    {
        return self::MIME_EXTENSIONS[$this->mimeType()] ?? [];
    }

    /** Canonical extension for the sniffed MIME type (falls back to the client's). */
    public function extension(): string
    {
        return $this->guessedExtensions()[0] ?? strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }

    public function isImage(): bool
    {
        return isset(self::MIME_EXTENSIONS[$this->mimeType()])
            && @getimagesize($this->tmpName) !== false;
    }

    public function moveTo(string $destination): bool
    {
        $dir = dirname($destination);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        // is_uploaded_file() is false under CLI/test harnesses, so fall back to rename.
        return is_uploaded_file($this->tmpName)
            ? move_uploaded_file($this->tmpName, $destination)
            : rename($this->tmpName, $destination);
    }
}
