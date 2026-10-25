<?php

namespace App\Services\Operations;

use App\Models\Incident;
use App\Models\IncidentPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Stores incident photos on the private local disk and serves them
 * only through the staff route. The file name is generated here.
 */
final class IncidentPhotos
{
    public const DISK = 'local';

    public const MAX_PHOTOS = 6;

    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @var array<string, string>
     */
    public const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function store(Incident $incident, UploadedFile $file): IncidentPhoto
    {
        $realPath = $file->getRealPath();
        $binary = is_string($realPath) ? file_get_contents($realPath) : false;

        if (! is_string($binary)) {
            throw ValidationException::withMessages([
                'photos' => 'A photo could not be read.',
            ]);
        }

        if (strlen($binary) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'photos' => 'Each photo may be up to 5 MB.',
            ]);
        }

        $info = @getimagesizefromstring($binary);

        if ($info === false || ! isset(self::EXTENSIONS[$info['mime']])) {
            throw ValidationException::withMessages([
                'photos' => 'Photos must be PNG, JPEG, WEBP, or GIF images.',
            ]);
        }

        $mime = $info['mime'];
        $path = 'incidents/'.$incident->tenant_id.'/'.$incident->id.'/'.Str::lower((string) Str::ulid()).'.'.self::EXTENSIONS[$mime];

        if (! Storage::disk(self::DISK)->put($path, $binary)) {
            throw ValidationException::withMessages([
                'photos' => 'A photo could not be stored.',
            ]);
        }

        try {
            return IncidentPhoto::query()->create([
                'tenant_id' => $incident->tenant_id,
                'incident_id' => $incident->id,
                'path' => $path,
                'mime' => $mime,
            ]);
        } catch (Throwable $throwable) {
            Storage::disk(self::DISK)->delete($path);

            throw $throwable;
        }
    }

    /**
     * @param  list<string>  $paths
     */
    public function deletePaths(array $paths): void
    {
        foreach ($paths as $path) {
            if ($this->ownedPath($path) !== null) {
                Storage::disk(self::DISK)->delete($path);
            }
        }
    }

    public function response(IncidentPhoto $photo): StreamedResponse
    {
        $path = $this->ownedPath($photo->path);
        $mime = $photo->mime;

        if ($path === null || ! isset(self::EXTENSIONS[$mime]) || ! str_contains($path, '/'.$photo->incident_id.'/') || ! Storage::disk(self::DISK)->exists($path)) {
            abort(404);
        }

        return Storage::disk(self::DISK)->response($path, 'photo.'.self::EXTENSIONS[$mime], [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="photo.'.self::EXTENSIONS[$mime].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function ownedPath(?string $path): ?string
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..') || str_contains($path, '\\')) {
            return null;
        }

        if (! str_starts_with($path, 'incidents/')) {
            return null;
        }

        return $path;
    }
}
