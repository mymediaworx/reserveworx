<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Branding\LogoStore;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class TenantBranding extends Model
{
    use BelongsToTenant, HasUlids;

    public const DEFAULT_PRIMARY = '#0E7C86';

    public const DEFAULT_ACCENT = '#F26B3A';

    protected $table = 'tenant_brandings';

    protected $fillable = [
        'tenant_id',
        'display_name',
        'logo_path',
        'logo_mime',
        'logo_url',
        'primary_color',
        'accent_color',
        'support_email',
        'support_phone',
    ];

    /**
     * The address a page or API client should use for the logo.
     * An uploaded file is served from this workspace's host. An external URL is returned as stored.
     */
    public function publicLogoUrl(?Request $request = null): ?string
    {
        $stored = app(LogoStore::class)->ownedPath($this->tenant_id, is_string($this->logo_path) ? $this->logo_path : null);

        if ($stored !== null) {
            $version = $this->updated_at?->getTimestamp() ?? 0;
            $path = '/api/v1/branding/logo?v='.$version;

            if ($request instanceof Request) {
                return rtrim($request->getSchemeAndHttpHost(), '/').$path;
            }

            return $path;
        }

        if (is_string($this->logo_url) && $this->logo_url !== '') {
            return $this->logo_url;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditSnapshot(): array
    {
        return [
            'display_name' => $this->display_name,
            'logo_url' => $this->logo_url,
            'has_uploaded_logo' => is_string($this->logo_path) && $this->logo_path !== '',
            'primary_color' => $this->primary_color,
            'accent_color' => $this->accent_color,
            'support_email' => $this->support_email,
            'support_phone' => $this->support_phone,
        ];
    }
}
