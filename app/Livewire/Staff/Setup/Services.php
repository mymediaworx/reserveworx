<?php

namespace App\Livewire\Staff\Setup;

use App\Enums\ServiceKind;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Location;
use App\Models\PriceRule;
use App\Models\PromoCode;
use App\Models\Service;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Services & prices')]
class Services extends Component
{
    use WithinTenant;

    public string $name = '';

    public string $kind = 'rental';

    public string $locationId = '';

    public string $promoCode = '';

    public string $promoType = 'percent';

    public int $promoValue = 10;

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Service::class);
    }

    public function create(): void
    {
        $this->authorize('create', Service::class);
        $tenantId = $this->tenant()->id;
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::enum(ServiceKind::class)],
            'locationId' => ['required', 'string', Rule::exists('locations', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId))],
        ]);

        $service = Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $this->locationId,
            'name' => $this->name,
            'kind' => $this->kind,
            'waitlist_enabled' => false,
        ]);

        $this->audit('service.created', $service, ['kind' => $service->kind->value]);
        $this->redirect('/staff/setup/services/'.$service->id, navigate: true);
    }

    public function createPromo(): void
    {
        $this->authorize('create', Service::class);
        $this->validate([
            'promoCode' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            'promoType' => ['required', 'in:percent,amount'],
            'promoValue' => $this->promoType === 'percent' ? ['required', 'integer', 'between:1,100'] : ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        PromoCode::query()->create([
            'tenant_id' => $this->tenant()->id,
            'code' => strtoupper($this->promoCode),
            'percent_off' => $this->promoType === 'percent' ? $this->promoValue : null,
            'amount_off_cents' => $this->promoType === 'amount' ? $this->promoValue * 100 : null,
            'active' => true,
        ]);

        $this->reset('promoCode');
        $this->flash = 'Promo code added.';
    }

    public function togglePromo(string $id): void
    {
        $this->authorize('create', Service::class);
        $promo = PromoCode::query()->find($id);
        abort_unless($promo instanceof PromoCode, 404);
        $promo->active = ! $promo->active;
        $promo->save();
    }

    public function render(): View
    {
        $locations = Location::query()->orderBy('name')->get();

        if ($this->locationId === '' && $locations->isNotEmpty()) {
            $this->locationId = $locations->first()->id;
        }

        $services = Service::query()->with(['location', 'durations'])->orderBy('name')->get();

        return view('livewire.staff.setup.services', [
            'locations' => $locations,
            'services' => $services,
            'fromPrices' => PriceRule::query()
                ->whereIn('service_id', $services->pluck('id'))
                ->get()
                ->groupBy('service_id')
                ->map(fn ($rules) => (int) $rules->min('amount_cents')),
            'promos' => PromoCode::query()->orderBy('code')->get(),
            'canManage' => $this->allows('inventory.manage'),
        ]);
    }
}
