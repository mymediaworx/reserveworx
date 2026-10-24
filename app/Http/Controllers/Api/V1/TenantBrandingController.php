<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateTenantBrandingRequest;
use App\Http\Resources\TenantBrandingResource;
use App\Models\TenantBranding;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Branding\LogoStore;
use App\Services\Branding\WorkspaceProfiles;
use Illuminate\Http\UploadedFile;

class TenantBrandingController extends Controller
{
    public function __construct(
        private readonly WorkspaceProfiles $profiles,
        private readonly LogoStore $logos,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): TenantBrandingResource
    {
        $branding = $this->profiles->branding();
        $this->authorize('view', $branding);

        return new TenantBrandingResource($branding);
    }

    public function update(UpdateTenantBrandingRequest $request): TenantBrandingResource
    {
        $branding = $request->branding();
        $before = $branding->auditSnapshot();

        if ($request->exists('display_name')) {
            $branding->display_name = $request->string('display_name')->toString();
        }

        foreach (['primary_color', 'accent_color', 'support_email', 'support_phone'] as $field) {
            if ($request->exists($field)) {
                $branding->setAttribute($field, $request->validated($field));
            }
        }

        $this->applyLogo($request, $branding);
        $branding->save();

        $actor = $request->user();

        $this->audit->log(
            action: 'branding.updated',
            subject: $branding,
            properties: [
                'before' => $before,
                'after' => $branding->auditSnapshot(),
            ],
            actor: $actor instanceof User ? $actor : null,
            request: $request,
        );

        return new TenantBrandingResource($branding);
    }

    private function applyLogo(UpdateTenantBrandingRequest $request, TenantBranding $branding): void
    {
        $previous = $branding->logo_path;

        if ($request->boolean('remove_logo')) {
            $this->logos->delete($branding->tenant_id, $previous);
            $branding->logo_path = null;
            $branding->logo_mime = null;
            $branding->logo_url = null;

            return;
        }

        $upload = $request->file('logo');

        if ($upload instanceof UploadedFile || $request->filled('logo_base64')) {
            $stored = $upload instanceof UploadedFile
                ? $this->logos->storeUpload($branding->tenant_id, $upload)
                : $this->logos->storeEncoded($branding->tenant_id, $request->string('logo_base64')->toString());

            if ($previous !== $stored['path']) {
                $this->logos->delete($branding->tenant_id, $previous);
            }

            $branding->logo_path = $stored['path'];
            $branding->logo_mime = $stored['mime'];
            $branding->logo_url = null;

            return;
        }

        if ($request->exists('logo_url')) {
            $url = $request->validated('logo_url');
            $branding->logo_url = is_string($url) && $url !== '' ? $url : null;

            if (is_string($url) && $url !== '') {
                $this->logos->delete($branding->tenant_id, $previous);
                $branding->logo_path = null;
                $branding->logo_mime = null;
            }
        }
    }
}
