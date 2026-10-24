<?php

namespace App\Services\Branding;

use App\Models\TenantBranding;

/**
 * Branding exposed to existing views. Layouts read these properties when they want them.
 */
final readonly class WorkspaceBranding
{
    public function __construct(
        public ?string $tenantId,
        public string $displayName,
        public ?string $logoUrl,
        public string $primaryColor,
        public string $accentColor,
        public ?string $supportEmail,
        public ?string $supportPhone,
    ) {}

    public static function blank(): self
    {
        return new self(
            null,
            '',
            null,
            TenantBranding::DEFAULT_PRIMARY,
            TenantBranding::DEFAULT_ACCENT,
            null,
            null,
        );
    }
}
