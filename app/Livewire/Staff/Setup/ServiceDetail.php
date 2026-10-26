<?php

namespace App\Livewire\Staff\Setup;

use App\Enums\DepartureStatus;
use App\Enums\DurationUnit;
use App\Enums\ResourceType;
use App\Enums\ServiceKind;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Asset;
use App\Models\Departure;
use App\Models\Pool;
use App\Models\PriceRule;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\ServiceDuration;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.staff')]
class ServiceDetail extends Component
{
    use WithinTenant;

    #[Locked]
    public string $serviceId = '';

    public ?int $minParticipants = null;

    public ?int $maxParticipants = null;

    public bool $waitlist = false;

    public string $durationName = '';

    public int $durationQuantity = 1;

    public string $durationUnit = 'hours';

    public string $requirementResource = '';

    public int $requirementQuantity = 1;

    public ?int $requirementPer = null;

    public string $priceName = '';

    public string $priceKind = 'base';

    public string $priceAmount = '';

    public string $priceWindow = 'always';

    public ?int $priceDuration = null;

    public string $priceMinParty = '';

    public string $priceMaxParty = '';

    public string $departureDate = '';

    public string $departureTime = '10:00';

    public int $departureMinutes = 120;

    public ?int $departureSeats = 10;

    public bool $departurePrivate = false;

    public ?string $flash = null;

    public function mount(string $service): void
    {
        $model = $this->service($service);
        $this->authorize('view', $model);
        $this->serviceId = $model->id;
        $this->minParticipants = $model->min_participants;
        $this->maxParticipants = $model->max_participants;
        $this->waitlist = $model->waitlist_enabled;
        $this->departureDate = CarbonImmutable::now($model->location->timezone ?? 'UTC')->addDay()->toDateString();
    }

    public function saveSettings(): void
    {
        $service = $this->editable();
        $this->validate([
            'minParticipants' => ['nullable', 'integer', 'min:1'],
            'maxParticipants' => ['nullable', 'integer', 'min:1'],
            'waitlist' => ['boolean'],
        ]);

        if ($this->minParticipants !== null && $this->maxParticipants !== null && $this->maxParticipants < $this->minParticipants) {
            $this->addError('maxParticipants', 'The maximum must be at least the minimum.');

            return;
        }

        $service->fill([
            'min_participants' => $this->minParticipants,
            'max_participants' => $this->maxParticipants,
            'waitlist_enabled' => $this->waitlist,
        ])->save();
        $this->audit('service.updated', $service);
        $this->flash = 'Saved.';
    }

    public function addDuration(): void
    {
        $service = $this->editable();
        $this->validate([
            'durationName' => ['required', 'string', 'max:80'],
            'durationQuantity' => ['required', 'integer', 'min:1', 'max:10080'],
            'durationUnit' => ['required', 'in:minutes,hours'],
        ]);
        $unit = DurationUnit::from($this->durationUnit);
        $minutes = $unit === DurationUnit::Hours ? $this->durationQuantity * 60 : $this->durationQuantity;

        if ($minutes > 10080) {
            $this->addError('durationQuantity', 'That rental length is outside the supported range.');

            return;
        }

        $duration = ServiceDuration::query()->create([
            'tenant_id' => $this->tenant()->id,
            'service_id' => $service->id,
            'name' => $this->durationName,
            'quantity' => $this->durationQuantity,
            'unit' => $unit,
            'duration_minutes' => $minutes,
        ]);
        $this->audit('service.duration_created', $duration, ['service_id' => $service->id, 'duration_minutes' => $minutes]);
        $this->reset('durationName');
        $this->flash = 'Length added.';
    }

    public function addRequirement(): void
    {
        $service = $this->editable();
        $this->validate([
            'requirementResource' => ['required', 'string', 'regex:/^(asset|pool):[0-9A-Za-z]+$/'],
            'requirementQuantity' => ['required', 'integer', 'min:1'],
            'requirementPer' => ['nullable', 'integer', 'min:1'],
        ]);
        [$type, $id] = explode(':', $this->requirementResource, 2);
        $resourceType = ResourceType::from($type);
        $resource = $resourceType === ResourceType::Asset ? Asset::query()->find($id) : Pool::query()->find($id);

        if ($resource === null || $resource->location_id !== $service->location_id) {
            $this->addError('requirementResource', 'That gear is not at this service’s location.');

            return;
        }

        $requirement = ResourceRequirement::query()->create([
            'tenant_id' => $this->tenant()->id,
            'service_id' => $service->id,
            'resource_type' => $resourceType,
            'resource_id' => $resource->id,
            'quantity' => $this->requirementQuantity,
            'per_participants' => $this->requirementPer,
        ]);
        $this->audit('service.requirement_created', $requirement, [
            'service_id' => $service->id,
            'resource_type' => $resourceType->value,
            'quantity' => $requirement->quantity,
        ]);
        $this->reset('requirementQuantity', 'requirementPer');
        $this->flash = 'Gear linked.';
    }

    public function addPrice(): void
    {
        $service = $this->editable();
        $this->validate([
            'priceName' => ['required', 'string', 'max:80'],
            'priceKind' => ['required', 'in:base,per_person,duration'],
            'priceAmount' => ['required', 'numeric', 'min:0', 'max:100000'],
            'priceWindow' => ['required', 'in:always,weekday,weekend'],
            'priceDuration' => $this->priceKind === 'duration' ? ['required', 'integer', 'min:1'] : ['nullable', 'integer', 'min:1'],
            'priceMinParty' => ['nullable', 'integer', 'min:1', 'max:500'],
            'priceMaxParty' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $minParty = $this->priceMinParty === '' ? null : (int) $this->priceMinParty;
        $maxParty = $this->priceMaxParty === '' ? null : (int) $this->priceMaxParty;

        if ($maxParty !== null && $minParty === null) {
            $this->addError('priceMinParty', 'A party size needs a minimum.');

            return;
        }

        if ($maxParty !== null && $maxParty < $minParty) {
            $this->addError('priceMaxParty', 'The largest party must be at least the smallest.');

            return;
        }

        $rule = PriceRule::query()->create([
            'tenant_id' => $this->tenant()->id,
            'service_id' => $service->id,
            'name' => $this->priceName,
            'kind' => $this->priceKind,
            'amount_cents' => (int) round(((float) $this->priceAmount) * 100),
            'window' => $this->priceWindow,
            'duration_minutes' => $this->priceKind === 'duration' ? $this->priceDuration : null,
            'min_participants' => $minParty,
            'max_participants' => $maxParty,
        ]);
        $this->audit('price_rule.created', $rule, [
            'service_id' => $service->id,
            'min_participants' => $minParty,
            'max_participants' => $maxParty,
        ]);
        $this->reset('priceName', 'priceAmount', 'priceMinParty', 'priceMaxParty');
        $this->flash = 'Price added.';
    }

    public function addDeparture(): void
    {
        $service = $this->editable();
        abort_unless($service->kind === ServiceKind::Tour, 422);
        $this->authorize('create', Departure::class);
        $this->validate([
            'departureDate' => ['required', 'date_format:Y-m-d'],
            'departureTime' => ['required', 'date_format:H:i'],
            'departureMinutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'departureSeats' => $this->departurePrivate ? ['nullable', 'integer', 'min:1'] : ['required', 'integer', 'min:1'],
            'departurePrivate' => ['boolean'],
        ]);
        $zone = $service->location->timezone ?? 'UTC';
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->departureDate.' '.$this->departureTime, $zone)->utc();

        $departure = Departure::query()->create([
            'tenant_id' => $this->tenant()->id,
            'service_id' => $service->id,
            'location_id' => $service->location_id,
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($this->departureMinutes),
            'private' => $this->departurePrivate,
            'seats' => $this->departurePrivate ? null : $this->departureSeats,
            'status' => DepartureStatus::Scheduled,
        ]);
        $this->audit('departure.scheduled', $departure, [
            'service_id' => $service->id,
            'starts_at' => $departure->starts_at->toIso8601String(),
        ]);
        $this->flash = 'Departure scheduled.';
    }

    public function render(): View
    {
        $service = $this->service($this->serviceId)->load(['location', 'durations', 'requirements']);
        $zone = $service->location->timezone ?? 'UTC';

        return view('livewire.staff.setup.service-detail', [
            'service' => $service,
            'zone' => $zone,
            'prices' => PriceRule::query()->where('service_id', $service->id)->orderBy('kind')->orderBy('amount_cents')->get(),
            'pools' => Pool::query()->where('location_id', $service->location_id)->orderBy('name')->get(),
            'assets' => Asset::query()->where('location_id', $service->location_id)->orderBy('name')->get(),
            'resourceNames' => Pool::query()->pluck('name', 'id')->union(Asset::query()->pluck('name', 'id')),
            'departures' => $service->kind === ServiceKind::Tour
                ? Departure::query()->where('service_id', $service->id)->where('starts_at', '>=', now()->subDay())->orderBy('starts_at')->limit(30)->get()
                : collect(),
            'canManage' => $this->allows('inventory.manage'),
        ])->title($service->name);
    }

    private function service(string $id): Service
    {
        $service = Service::query()->with('location')->find($id);
        abort_unless($service instanceof Service, 404);

        return $service;
    }

    private function editable(): Service
    {
        $this->flash = null;
        $service = $this->service($this->serviceId);
        $this->authorize('update', $service);

        return $service;
    }
}
