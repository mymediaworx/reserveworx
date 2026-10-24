<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Service;
use App\Models\WidgetToken;
use App\Services\Branding\CurrentWorkspaceBranding;
use App\Services\Widget\WidgetTokens;
use App\Support\BrandPalette;
use App\Tenancy\TenantScope;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Share & embed: one place to copy the booking link, a "Book now" button, an
 * on-page embed, or a pop-up for the operator's own website.
 *
 * Links use the workspace's hosted booking token (WidgetTokens::hostedPlain),
 * which is stable, so every code can be copied again at any time. Separate
 * named links (the widget-token API) stay available under "More links".
 */
#[Layout('layouts.staff')]
#[Title('Share & embed')]
class Share extends Component
{
    use WithinTenant;

    public const FORMATS = ['link', 'button', 'inline', 'popup'];

    #[Url(as: 'service')]
    public string $target = 'all';

    #[Url]
    public string $format = 'link';

    public string $buttonText = 'Book now';

    public string $name = '';

    public ?string $newUrl = null;

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Booking::class);

        if (! in_array($this->format, self::FORMATS, true)) {
            $this->format = 'link';
        }
    }

    public function choose(string $format): void
    {
        $this->format = in_array($format, self::FORMATS, true) ? $format : 'link';
    }

    public function createLink(WidgetTokens $tokens): void
    {
        $this->authorize('create', Booking::class);
        $this->validate(['name' => ['required', 'string', 'max:80']]);
        $issued = $tokens->issue($this->tenant(), $this->name, null);
        $this->audit('widget_token.created', $issued['token'], ['name' => $this->name]);
        $this->newUrl = $this->origin().'/book/'.$issued['plain'];
        $this->reset('name');
        $this->flash = null;
    }

    public function revoke(string $id): void
    {
        $this->authorize('create', Booking::class);
        $token = WidgetToken::query()->find($id);
        abort_unless($token instanceof WidgetToken, 404);
        $token->revoked_at = now();
        $token->save();
        $this->audit('widget_token.revoked', $token);
        $this->newUrl = null;
        $this->flash = 'Link turned off. Anyone using it now sees a not-found page.';
    }

    public function render(WidgetTokens $tokens): View
    {
        $tenant = $this->tenant();
        $origin = $this->origin();
        $hosted = $tokens->hostedPlain($tenant);
        $services = Service::query()->orderBy('name')->get(['id', 'name']);
        $service = $this->target !== 'all' ? $services->firstWhere('id', $this->target) : null;

        if ($this->target !== 'all' && ! $service instanceof Service) {
            $this->target = 'all';
        }

        $url = $service instanceof Service
            ? $origin.'/book/'.$hosted.'/services/'.$service->id
            : $origin.'/';
        $palette = BrandPalette::from($this->brandingColor('primary'), $this->brandingColor('accent'));
        $label = trim($this->buttonText) !== '' ? mb_substr(trim($this->buttonText), 0, 40) : 'Book now';
        $businessName = $this->businessName();

        return view('livewire.staff.share', [
            'url' => $url,
            'origin' => $origin,
            'services' => $services,
            'service' => $service,
            'businessName' => $businessName,
            'snippets' => [
                'button' => $this->buttonSnippet($url, $label, $palette, false),
                'inline' => '<iframe src="'.e($url).'" title="'.e('Book with '.$businessName).'" data-reserveworx style="width:100%;min-height:720px;border:0" loading="lazy"></iframe>'."\n"
                    .'<script src="'.e($origin.'/embed.js').'" async></script>',
                'popup' => $this->buttonSnippet($url, $label, $palette, true)."\n"
                    .'<script src="'.e($origin.'/embed.js').'" async></script>',
            ],
            'buttonPreview' => $this->buttonSnippet($url, $label, $palette, false),
            'popupPreview' => $this->buttonSnippet($url, $label, $palette, true),
            'smsHref' => 'sms:?&body='.rawurlencode('Book with '.$businessName.': '.$url),
            'mailHref' => 'mailto:?subject='.rawurlencode('Book with '.$businessName).'&body='.rawurlencode($url),
            'tokens' => WidgetToken::query()->where('name', '!=', 'Hosted booking page')->orderByDesc('created_at')->get(),
            'canManage' => $this->allows('bookings.manage'),
        ]);
    }

    /**
     * A plain HTML link styled as a button. Works on any website builder with no
     * script. The pop-up version adds data-reserveworx-popup for embed.js and
     * still opens the booking page in a new tab if the script never loads.
     */
    private function buttonSnippet(string $url, string $label, BrandPalette $palette, bool $popup): string
    {
        $style = 'display:inline-block;padding:14px 26px;border-radius:10px;background:'.$palette->primary
            .';color:'.BrandPalette::bestText($palette->primary)
            .';font:600 16px/1.2 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;text-decoration:none';

        return '<a href="'.e($url).'"'.($popup ? ' data-reserveworx-popup' : '').' target="_blank" rel="noopener" style="'.$style.'">'.e($label).'</a>';
    }

    /**
     * Scheme and host customers should use: the workspace's primary verified
     * domain, or the host staff are signed in on.
     */
    private function origin(): string
    {
        $primary = Domain::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenant()->id)
            ->whereNotNull('verified_at')
            ->orderByDesc('is_primary')
            ->orderBy('hostname')
            ->value('hostname');
        $request = request();
        $host = is_string($primary) && $primary !== '' ? $primary : $request->getHost();

        // Same host staff are on, outside production: keep its port (local development).
        if ($host === $request->getHost() && ! app()->environment('production')) {
            return $request->getSchemeAndHttpHost();
        }

        $scheme = app()->environment('production') ? 'https' : $request->getScheme();

        return $scheme.'://'.$host;
    }

    private function brandingColor(string $which): string
    {
        $branding = app(CurrentWorkspaceBranding::class)->resolve();

        return $which === 'primary' ? $branding->primaryColor : $branding->accentColor;
    }

    private function businessName(): string
    {
        $branding = app(CurrentWorkspaceBranding::class)->resolve();

        return $branding->displayName !== '' ? $branding->displayName : $this->tenant()->name;
    }
}
