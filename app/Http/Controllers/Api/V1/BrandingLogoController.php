<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TenantBranding;
use App\Services\Branding\LogoStore;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandingLogoController extends Controller
{
    public function __construct(private readonly LogoStore $logos) {}

    public function show(): StreamedResponse
    {
        $branding = TenantBranding::query()->first();

        if (! $branding instanceof TenantBranding) {
            abort(404);
        }

        $path = $this->logos->ownedPath($branding->tenant_id, $branding->logo_path);
        $mime = is_string($branding->logo_mime) ? $branding->logo_mime : '';

        if ($path === null || ! isset(LogoStore::EXTENSIONS[$mime]) || ! Storage::disk(LogoStore::DISK)->exists($path)) {
            abort(404);
        }

        return Storage::disk(LogoStore::DISK)->response($path, 'logo', [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
