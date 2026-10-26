<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Models\TenantSetupGuide;
use App\Services\Onboarding\SetupGuide;
use App\Tenancy\Access;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Step-by-step setup walkthrough for owners and managers. Links to the real
 * settings pages; it never edits prices, tax, fees or refunds itself.
 */
#[Layout('layouts.staff')]
#[Title('Setup guide')]
class SetupGuidePage extends Component
{
    use WithinTenant;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->ensureOwnerOrManager();
    }

    public function markDone(string $key, SetupGuide $guide): void
    {
        $this->ensureOwnerOrManager();
        $guide->mark($this->tenant(), $key, TenantSetupGuide::DONE);
        $this->notice = 'Step marked done.';
    }

    public function skip(string $key, SetupGuide $guide): void
    {
        $this->ensureOwnerOrManager();
        $guide->mark($this->tenant(), $key, TenantSetupGuide::SKIPPED);
        $this->notice = 'Step skipped.';
    }

    public function undo(string $key, SetupGuide $guide): void
    {
        $this->ensureOwnerOrManager();
        $guide->mark($this->tenant(), $key, null);
        $this->notice = 'Step moved back to to do.';
    }

    public function hideGuide(SetupGuide $guide): void
    {
        $this->ensureOwnerOrManager();
        $guide->setHidden($this->tenant(), true);
        $this->notice = 'Setup guide hidden from the home page. Turn it back on any time in Settings.';
    }

    public function showGuide(SetupGuide $guide): void
    {
        $this->ensureOwnerOrManager();
        $guide->setHidden($this->tenant(), false);
        $this->notice = 'Setup guide shown on the home page.';
    }

    public function render(SetupGuide $guide): View
    {
        $steps = $guide->steps($this->tenant());
        $complete = collect($steps)->where('state', '!=', 'todo')->count();

        return view('livewire.staff.setup-guide', [
            'steps' => $steps,
            'complete' => $complete,
            'total' => count($steps),
            'hidden' => $guide->isHidden($this->tenant()),
        ]);
    }

    private function ensureOwnerOrManager(): void
    {
        abort_unless(app(Access::class)->hasRole($this->currentUser(), $this->tenant()->id, 'owner', 'manager'), 403);
    }
}
