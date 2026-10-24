<?php

namespace App\Services\Branding;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores one workspace logo on the private local disk.
 * The file is served by the tenant host, not by a public storage link.
 */
final class LogoStore
{
    public const DISK = 'local';

    /**
     * @var array<string, string>
     */
    public const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * @return array{path: string, mime: string}
     */
    public function storeUpload(string $tenantId, UploadedFile $file): array
    {
        $realPath = $file->getRealPath();
        $binary = is_string($realPath) ? file_get_contents($realPath) : false;

        if (! is_string($binary)) {
            throw ValidationException::withMessages([
                'logo' => 'The logo could not be read.',
            ]);
        }

        return $this->storeBinary($tenantId, $binary, 'logo');
    }

    /**
     * @return array{path: string, mime: string}
     */
    public function storeEncoded(string $tenantId, string $encoded): array
    {
        $payload = trim($encoded);

        if (str_starts_with($payload, 'data:')) {
            $comma = strpos($payload, ',');
            $payload = $comma === false ? '' : substr($payload, $comma + 1);
        }

        $binary = base64_decode($payload, true);

        if (! is_string($binary) || $binary === '') {
            throw ValidationException::withMessages([
                'logo_base64' => 'The logo must be a PNG, JPEG, WEBP, or GIF image.',
            ]);
        }

        return $this->storeBinary($tenantId, $binary, 'logo_base64');
    }

    public function delete(string $tenantId, ?string $path): void
    {
        $owned = $this->ownedPath($tenantId, $path);

        if ($owned !== null) {
            Storage::disk(self::DISK)->delete($owned);
        }
    }

    public function ownedPath(string $tenantId, ?string $path): ?string
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return null;
        }

        $prefix = 'branding/'.$tenantId.'/';

        if (! str_starts_with($path, $prefix)) {
            return null;
        }

        return $path;
    }

    /**
     * @return array{path: string, mime: string}
     */
    private function storeBinary(string $tenantId, string $binary, string $errorKey): array
    {
        if (strlen($binary) > 2 * 1024 * 1024) {
            throw ValidationException::withMessages([
                $errorKey => 'The logo may not be greater than 2 MB.',
            ]);
        }

        $info = @getimagesizefromstring($binary);

        if ($info === false || ! isset(self::EXTENSIONS[$info['mime']])) {
            throw ValidationException::withMessages([
                $errorKey => 'The logo must be a PNG, JPEG, WEBP, or GIF image.',
            ]);
        }

        $mime = $info['mime'];

        $path = 'branding/'.$tenantId.'/'.Str::lower((string) Str::ulid()).'.'.self::EXTENSIONS[$mime];

        if (! Storage::disk(self::DISK)->put($path, $binary)) {
            throw ValidationException::withMessages([
                $errorKey => 'The logo could not be stored.',
            ]);
        }

        return ['path' => $path, 'mime' => $mime];
    }
}
