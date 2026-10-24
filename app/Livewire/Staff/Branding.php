<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Models\TenantBranding;
use App\Services\Branding\LogoStore;
use App\Services\Branding\WorkspaceProfiles;
use App\Support\BrandPalette;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A workspace's name, logo, and colors. Writes the same tenant_brandings row and
 * the same branding.updated audit entry as PATCH /api/v1/branding.
 */
#[Layout('layouts.staff')]
#[Title('Branding')]
class Branding extends Component
{
    use WithFileUploads, WithinTenant;

    /**
     * Starting points. The first is the product default.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PRESETS = [
        'BrandWorx' => [TenantBranding::DEFAULT_PRIMARY, TenantBranding::DEFAULT_ACCENT],
        'Lake' => ['#0E7C86', '#F26B3A'],
        'Ocean' => ['#1D4ED8', '#F59E0B'],
        'Sunset' => ['#C2410C', '#0E7490'],
        'Forest' => ['#166534', '#CA8A04'],
        'Plum' => ['#6D28D9', '#DB2777'],
        'Charcoal' => ['#1F2937', '#10B981'],
    ];

    public string $displayName = '';

    public string $primary = TenantBranding::DEFAULT_PRIMARY;

    public string $accent = TenantBranding::DEFAULT_ACCENT;

    public string $supportEmail = '';

    public string $supportPhone = '';

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    public ?string $flash = null;

    public function mount(WorkspaceProfiles $profiles): void
    {
        $branding = $profiles->branding();
        $this->authorize('view', $branding);
        $this->displayName = $branding->display_name;
        $this->primary = $branding->primary_color;
        $this->accent = $branding->accent_color;
        $this->supportEmail = (string) $branding->support_email;
        $this->supportPhone = (string) $branding->support_phone;
    }

    public function usePreset(string $name): void
    {
        $preset = self::PRESETS[$name] ?? null;

        if ($preset === null) {
            return;
        }

        [$this->primary, $this->accent] = $preset;
        $this->resetValidation(['primary', 'accent']);
        $this->flash = null;
    }

    public function updatedLogo(): void
    {
        $this->validateOnly('logo', ['logo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:2048']]);
    }

    public function save(WorkspaceProfiles $profiles, LogoStore $logos): void
    {
        $branding = $profiles->branding();
        $this->authorize('update', $branding);
        $this->flash = null;

        $this->primary = BrandPalette::normalize($this->primary) ?? $this->primary;
        $this->accent = BrandPalette::normalize($this->accent) ?? $this->accent;
        $this->validate([
            'displayName' => ['required', 'string', 'max:120'],
            'primary' => ['required', 'regex:'.BrandPalette::HEX],
            'accent' => ['required', 'regex:'.BrandPalette::HEX],
            'supportEmail' => ['nullable', 'email', 'max:255'],
            'supportPhone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+().\-\s]*$/'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:2048'],
        ], [
            'primary.regex' => 'Enter a color like #0F5C4D.',
            'accent.regex' => 'Enter a color like #F59E0B.',
        ]);

        $digits = strlen((string) preg_replace('/\D+/', '', $this->supportPhone));

        if ($this->supportPhone !== '' && ($digits < 7 || $digits > 15)) {
            $this->addError('supportPhone', 'Enter a phone number with 7 to 15 digits.');

            return;
        }

        $before = $branding->auditSnapshot();
        $branding->fill([
            'display_name' => trim($this->displayName),
            'primary_color' => $this->primary,
            'accent_color' => $this->accent,
            'support_email' => $this->supportEmail !== '' ? strtolower($this->supportEmail) : null,
            'support_phone' => $this->supportPhone !== '' ? $this->supportPhone : null,
        ]);

        if ($this->logo instanceof TemporaryUploadedFile) {
            $previous = $branding->logo_path;
            $stored = $logos->storeUpload($branding->tenant_id, $this->logo);

            if ($previous !== $stored['path']) {
                $logos->delete($branding->tenant_id, $previous);
            }

            $branding->logo_path = $stored['path'];
            $branding->logo_mime = $stored['mime'];
            $branding->logo_url = null;
            $this->logo = null;
        }

        $branding->save();
        $this->audit('branding.updated', $branding, ['before' => $before, 'after' => $branding->auditSnapshot()]);
        $this->flash = 'Saved. Your booking page and this app now use these colors.';
    }

    public function removeLogo(WorkspaceProfiles $profiles, LogoStore $logos): void
    {
        $branding = $profiles->branding();
        $this->authorize('update', $branding);
        $before = $branding->auditSnapshot();
        $logos->delete($branding->tenant_id, $branding->logo_path);
        $branding->fill(['logo_path' => null, 'logo_mime' => null, 'logo_url' => null])->save();
        $this->audit('branding.updated', $branding, ['before' => $before, 'after' => $branding->auditSnapshot()]);
        $this->logo = null;
        $this->flash = 'Logo removed. Your initials show instead.';
    }

    public function render(WorkspaceProfiles $profiles): View
    {
        $branding = $profiles->branding();
        $primary = BrandPalette::normalize($this->primary);
        $accent = BrandPalette::normalize($this->accent);
        $palette = BrandPalette::from($primary, $accent);
        $previewLogo = $this->logo instanceof TemporaryUploadedFile && ! $this->getErrorBag()->has('logo')
            ? $this->logo->temporaryUrl()
            : $branding->publicLogoUrl(request());

        return view('livewire.staff.branding', [
            'canUpdate' => $this->allows('settings.update'),
            'presets' => self::PRESETS,
            'palette' => $palette,
            'previewVariables' => collect($palette->variables())->map(fn (string $value, string $name): string => $name.':'.$value)->implode(';'),
            'previewLogo' => $previewLogo,
            'hasLogo' => $branding->publicLogoUrl() !== null,
            'primaryValid' => $primary !== null,
            'accentValid' => $accent !== null,
        ]);
    }
}
