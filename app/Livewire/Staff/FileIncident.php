<?php

namespace App\Livewire\Staff;

use App\Enums\IncidentSeverity;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Pool;
use App\Models\PricingSnapshot;
use App\Models\TenantSetting;
use App\Services\Operations\IncidentAssets;
use App\Services\Operations\IncidentPhotos;
use App\Services\Operations\IncidentReport;
use App\Support\Display;
use App\Support\DisplayZone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Files one damage or incident report for a booking. Used on the returns
 * desk, where the boat is already known, and on the booking page.
 */
class FileIncident extends Component
{
    use WithFileUploads, WithinTenant;

    #[Locked]
    public string $bookingId = '';

    #[Locked]
    public string $fixedAssetId = '';

    public string $assetId = '';

    public string $severity = '';

    public string $notes = '';

    public string $damageAmount = '';

    /** @var array<int, mixed> */
    public array $photos = [];

    public bool $blockAsset = false;

    public string $blockStarts = '';

    public string $blockEnds = '';

    public string $blockReason = '';

    public bool $missingAsset = false;

    public ?string $flash = null;

    public function mount(): void
    {
        $booking = $this->booking();
        $this->authorize('checkIn', $booking);

        if ($this->fixedAssetId !== '' && ! $this->assets($booking)->contains('id', $this->fixedAssetId)) {
            $this->missingAsset = true;
        }

        $start = CarbonImmutable::now($this->zone($booking));
        $this->blockStarts = $start->format('Y-m-d\TH:i');
        $this->blockEnds = $start->addDay()->format('Y-m-d\TH:i');
        $this->blockReason = 'Damage reported on this asset';
    }

    public function file(IncidentReport $reports): void
    {
        $this->reset('flash');
        $booking = $this->booking();
        $this->authorize('checkIn', $booking);
        abort_if($this->missingAsset, 404);

        $options = app(IncidentAssets::class)->options($booking);

        $this->validate([
            'severity' => ['required', Rule::enum(IncidentSeverity::class)],
            'notes' => ['required', 'string', 'min:3', 'max:2000'],
            'damageAmount' => ['nullable', 'string', 'max:20'],
            'assetId' => [$this->needsAssetChoice($options['assets'], $options['pools']) ? 'required' : 'nullable', 'string'],
            'photos' => ['array', 'max:'.IncidentPhotos::MAX_PHOTOS],
            'photos.*' => ['image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
            'blockStarts' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'blockEnds' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'blockReason' => ['nullable', 'string', 'max:160'],
        ], [
            'severity.required' => 'Choose how serious it is.',
            'notes.required' => 'Add a short note about what happened.',
            'notes.min' => 'Add a short note about what happened.',
            'assetId.required' => 'Choose a boat on this booking.',
            'photos.max' => 'You can attach up to 6 photos.',
            'photos.*.image' => 'Photos must be PNG, JPEG, WEBP, or GIF images.',
            'photos.*.mimes' => 'Photos must be PNG, JPEG, WEBP, or GIF images.',
            'photos.*.max' => 'Each photo may be up to 5 MB.',
        ]);

        [$asset, $pool] = $this->subject($options['assets'], $options['pools']);

        if (! $asset instanceof Asset && ! $pool instanceof Pool) {
            $this->addError('assetId', 'Choose a boat on this booking.');

            return;
        }

        try {
            $cents = $this->damageCents();
            $maintenance = $asset instanceof Asset ? $this->maintenanceWindow($booking) : null;
            $uploads = [];

            foreach ($this->photos as $photo) {
                if ($photo instanceof UploadedFile) {
                    $uploads[] = $photo;
                }
            }

            $result = $reports->file(
                $booking,
                $asset,
                $pool,
                IncidentSeverity::from($this->severity),
                $this->notes,
                $cents,
                $uploads,
                $this->currentUser(),
                $maintenance,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $message = $messages[0] ?? 'Check the report and try again.';
                $this->addError(is_string($key) ? $key : 'notes', is_string($message) ? $message : 'Check the report and try again.');
            }

            return;
        }

        $incident = $result['incident'];
        $this->audit('incident.reported', $incident, [
            'booking_id' => $booking->id,
            'asset_id' => $asset?->id,
            'pool_id' => $pool?->id,
            'severity' => $incident->severity instanceof IncidentSeverity ? $incident->severity->value : $this->severity,
            'damage_cents' => $incident->damage_cents,
            'photo_count' => $result['photo_count'],
            'maintenance_window_id' => $incident->maintenance_window_id,
            'flagged_bookings' => $result['flagged'],
        ]);

        if (is_string($incident->maintenance_window_id) && $incident->maintenance_window_id !== '') {
            $window = MaintenanceWindow::query()->find($incident->maintenance_window_id);

            if ($window instanceof MaintenanceWindow) {
                $this->audit('maintenance.scheduled', $window, [
                    'kind' => $window->kind->value,
                    'asset_id' => $window->asset_id,
                    'pool_id' => $window->pool_id,
                    'starts_at' => $window->starts_at->utc()->toIso8601String(),
                    'ends_at' => $window->ends_at->utc()->toIso8601String(),
                    'flagged_bookings' => $result['flagged'],
                    'incident_id' => $incident->id,
                ]);
            }
        }

        $currency = $this->currency($booking);
        $message = 'Report filed.';

        if (is_int($incident->damage_cents) && $incident->damage_cents > 0) {
            $message .= ' '.Display::money($incident->damage_cents, $currency).' was added to the customer total. No payment was taken.';
        }

        if (is_string($incident->maintenance_window_id) && $incident->maintenance_window_id !== '') {
            $flagged = $result['flagged'];
            $message .= ' It is on the Maintenance page.';
            $message .= $flagged === 0
                ? ' No future bookings are affected.'
                : ' '.$flagged.' future '.($flagged === 1 ? 'booking is' : 'bookings are').' flagged. Nothing was cancelled.';
        }

        $this->flash = $message;
        $this->reset('notes', 'damageAmount', 'photos', 'blockAsset', 'severity');
        $this->resetValidation();
        $start = CarbonImmutable::now($this->zone($booking));
        $this->blockStarts = $start->format('Y-m-d\TH:i');
        $this->blockEnds = $start->addDay()->format('Y-m-d\TH:i');
        $this->blockReason = 'Damage reported on this asset';
    }

    public function render(): View
    {
        $booking = $this->booking();
        $options = app(IncidentAssets::class)->options($booking);
        $assets = $options['assets'];
        $pools = $options['pools'];
        $gearPool = $assets->isEmpty() && $pools->count() === 1 ? $pools->first() : null;

        return view('livewire.staff.file-incident', [
            'booking' => $booking,
            'assets' => $assets,
            'pools' => $pools,
            'gearPool' => $gearPool instanceof Pool ? $gearPool : null,
            'unassigned' => ! app(IncidentAssets::class)->bookingHasResource($booking) || ($assets->isEmpty() && $pools->isEmpty()),
            'maintenanceAllowed' => $this->chosenAsset($assets) instanceof Asset,
            'fixedAsset' => $this->fixedAssetId !== '' ? $assets->firstWhere('id', $this->fixedAssetId) : null,
            'reports' => Incident::query()
                ->with(['asset', 'pool', 'photos'])
                ->where('booking_id', $booking->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get(),
            'currency' => $this->currency($booking),
            'serious' => IncidentSeverity::Serious->value,
        ]);
    }

    private function booking(): Booking
    {
        $booking = Booking::query()->with('location')->find($this->bookingId);
        abort_unless($booking instanceof Booking, 404);

        return $booking;
    }

    /**
     * @return Collection<int, Asset>
     */
    private function assets(Booking $booking): Collection
    {
        return app(IncidentAssets::class)->forBooking($booking);
    }

    /**
     * @param  Collection<int, Asset>  $assets
     * @param  Collection<int, Pool>  $pools
     */
    private function needsAssetChoice(Collection $assets, Collection $pools): bool
    {
        if ($this->fixedAssetId !== '') {
            return false;
        }

        return ! ($assets->isEmpty() && $pools->count() === 1);
    }

    /**
     * @param  Collection<int, Asset>  $assets
     * @param  Collection<int, Pool>  $pools
     * @return array{0: ?Asset, 1: ?Pool}
     */
    private function subject(Collection $assets, Collection $pools): array
    {
        if ($this->fixedAssetId !== '') {
            $fixed = $assets->firstWhere('id', $this->fixedAssetId);

            return [$fixed instanceof Asset ? $fixed : null, null];
        }

        if (str_starts_with($this->assetId, IncidentAssets::POOL_PREFIX)) {
            $poolId = substr($this->assetId, strlen(IncidentAssets::POOL_PREFIX));
            $pool = $pools->firstWhere('id', $poolId);

            return [null, $pool instanceof Pool ? $pool : null];
        }

        if ($this->assetId !== '') {
            $asset = $assets->firstWhere('id', $this->assetId);

            return [$asset instanceof Asset ? $asset : null, null];
        }

        if ($assets->isEmpty() && $pools->count() === 1) {
            $pool = $pools->first();

            return [null, $pool instanceof Pool ? $pool : null];
        }

        return [null, null];
    }

    /**
     * @param  Collection<int, Asset>  $assets
     */
    private function chosenAsset(Collection $assets): ?Asset
    {
        [$asset] = $this->subject($assets, new Collection);

        return $asset;
    }

    private function damageCents(): ?int
    {
        $raw = trim($this->damageAmount);

        if ($raw === '') {
            return null;
        }

        if (! preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $raw)) {
            throw ValidationException::withMessages([
                'damageAmount' => 'Enter the amount in dollars, like 25.50, or leave it blank.',
            ]);
        }

        [$dollars, $fraction] = array_pad(explode('.', $raw, 2), 2, '0');
        $cents = ((int) $dollars * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        if ($cents < 1) {
            return null;
        }

        if ($cents > 10_000_000) {
            throw ValidationException::withMessages([
                'damageAmount' => 'Enter an amount of $100,000 or less.',
            ]);
        }

        return $cents;
    }

    /**
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, reason: string}|null
     */
    private function maintenanceWindow(Booking $booking): ?array
    {
        if ($this->severity !== IncidentSeverity::Serious->value || ! $this->blockAsset) {
            return null;
        }

        $reason = trim($this->blockReason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'blockReason' => 'Add a short reason for the maintenance.',
            ]);
        }

        $zone = $this->zone($booking);
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->blockStarts, $zone);
        $endsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->blockEnds, $zone);

        if (! $startsAt instanceof CarbonImmutable || ! $endsAt instanceof CarbonImmutable) {
            throw ValidationException::withMessages([
                'blockEnds' => 'Enter the start and end.',
            ]);
        }

        if (! $endsAt->greaterThan($startsAt)) {
            throw ValidationException::withMessages([
                'blockEnds' => 'The end must be after the start.',
            ]);
        }

        if (! $endsAt->greaterThan(CarbonImmutable::now())) {
            throw ValidationException::withMessages([
                'blockEnds' => 'That time has already passed.',
            ]);
        }

        return [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => mb_substr($reason, 0, 160),
        ];
    }

    private function currency(Booking $booking): string
    {
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if ($snapshot instanceof PricingSnapshot && $snapshot->currency !== '') {
            return $snapshot->currency;
        }

        $setting = TenantSetting::query()->where('tenant_id', $booking->tenant_id)->first();

        return $setting instanceof TenantSetting && $setting->currency !== '' ? $setting->currency : 'USD';
    }

    private function zone(Booking $booking): string
    {
        return DisplayZone::forLocation($booking->location);
    }
}
