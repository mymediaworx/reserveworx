<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Models\Booking;
use App\Models\WidgetToken;
use App\Services\Widget\WidgetTokens;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Links to the public booking widget (/book/{token}). Tokens are stored hashed,
 * so a new link is shown once, right after it is created, and never again.
 * Same rules as the widget-token API: bookings.view to list, bookings.manage to
 * create or revoke.
 */
#[Layout('layouts.staff')]
#[Title('Booking links')]
class BookingLinks extends Component
{
    use WithinTenant;

    public string $name = '';

    public ?string $newUrl = null;

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Booking::class);
    }

    public function create(WidgetTokens $tokens): void
    {
        $this->authorize('create', Booking::class);
        $this->validate(['name' => ['required', 'string', 'max:80']]);
        $issued = $tokens->issue($this->tenant(), $this->name, null);
        $this->audit('widget_token.created', $issued['token'], ['name' => $this->name]);
        $this->newUrl = url('/book/'.$issued['plain']);
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

    public function render(): View
    {
        return view('livewire.staff.booking-links', [
            'tokens' => WidgetToken::query()->orderByDesc('created_at')->get(),
            'canManage' => $this->allows('bookings.manage'),
        ]);
    }
}
