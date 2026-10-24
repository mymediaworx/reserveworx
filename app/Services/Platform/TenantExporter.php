<?php

namespace App\Services\Platform;

use App\Models\AddOn;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\AvailabilitySchedule;
use App\Models\Blackout;
use App\Models\Booking;
use App\Models\BookingAddOn;
use App\Models\BookingLineItem;
use App\Models\CapacityLedger;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DisclosureVersion;
use App\Models\Domain;
use App\Models\Hold;
use App\Models\Incident;
use App\Models\IntegrationOutbox;
use App\Models\Location;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceWindow;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\Participant;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\Pool;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\PromoCode;
use App\Models\Refund;
use App\Models\RentalCheckout;
use App\Models\RentalReturn;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantDataExport;
use App\Models\TenantNotificationSetting;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Models\WidgetToken;
use App\Services\AuditLogger;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes one workspace's rows to a private JSON file so termination has a copy.
 * The read runs under that workspace's tenant context. A row from any other
 * workspace aborts the export before a file or a row is stored.
 */
class TenantExporter
{
    private const DISK = 'local';

    public function __construct(
        private readonly TenantManager $tenants,
        private readonly AuditLogger $audit,
    ) {}

    public function create(Tenant $tenant, User $actor, ?Request $request = null): TenantDataExport
    {
        $previous = $this->tenants->get();
        $this->tenants->set($tenant);

        try {
            $tables = $this->tables($tenant);
            $tables['users'] = $this->members($tables['memberships'] ?? []);
            $encoded = json_encode([
                'exported_at' => Carbon::now('UTC')->toIso8601String(),
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status->value,
                ],
                'tables' => $tables,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $export = new TenantDataExport;
            $export->id = $export->newUniqueId();
            $path = 'tenant-exports/'.$tenant->id.'/'.$export->id.'.json';

            if (! Storage::disk(self::DISK)->put($path, $encoded)) {
                throw new TenantExportException('The export file could not be written.');
            }

            try {
                $export->forceFill([
                    'tenant_id' => $tenant->id,
                    'actor_user_id' => $actor->id,
                    'disk' => self::DISK,
                    'path' => $path,
                    'bytes' => strlen($encoded),
                    'row_counts' => array_map(count(...), $tables),
                    'completed_at' => Carbon::now('UTC'),
                ])->save();
            } catch (\Throwable $exception) {
                Storage::disk(self::DISK)->delete($path);

                throw $exception;
            }

            $this->audit->log(
                action: 'tenant.data_exported',
                subject: $export,
                properties: ['bytes' => $export->bytes, 'row_counts' => $export->row_counts],
                tenantId: $tenant->id,
                actor: $actor,
                request: $request,
                useContextTenant: false,
            );

            return $export;
        } finally {
            if ($previous instanceof Tenant) {
                $this->tenants->set($previous);
            } else {
                $this->tenants->forget();
            }
        }
    }

    public function isReady(Tenant $tenant): bool
    {
        $exports = TenantDataExport::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('completed_at')
            ->get();

        foreach ($exports as $export) {
            if (Storage::disk($export->disk)->exists($export->path)) {
                return true;
            }
        }

        return false;
    }

    public function download(Tenant $tenant, string $exportId): StreamedResponse
    {
        $export = TenantDataExport::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->whereKey($exportId)
            ->first();

        if (! $export instanceof TenantDataExport || ! Storage::disk($export->disk)->exists($export->path)) {
            abort(404);
        }

        return Storage::disk($export->disk)->download(
            $export->path,
            $tenant->slug.'-export.json',
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function tables(Tenant $tenant): array
    {
        $tables = [];

        foreach ($this->models() as $class) {
            $rows = [];

            foreach ($class::query()->orderBy('id')->get() as $model) {
                $owner = $model->getAttribute('tenant_id');

                if ($owner !== $tenant->id) {
                    throw new TenantExportException('A row from another workspace was read.');
                }

                $rows[] = $this->publicAttributes($model);
            }

            $tables[$class::query()->getModel()->getTable()] = $rows;
        }

        return $tables;
    }

    /**
     * @param  list<array<string, mixed>>  $memberships
     * @return list<array<string, mixed>>
     */
    private function members(array $memberships): array
    {
        $ids = [];

        foreach ($memberships as $membership) {
            $userId = $membership['user_id'] ?? null;

            if (is_string($userId)) {
                $ids[] = $userId;
            }
        }

        if ($ids === []) {
            return [];
        }

        $rows = [];

        foreach (User::query()->whereIn('id', $ids)->orderBy('id')->get() as $user) {
            $rows[] = $this->publicAttributes($user);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicAttributes(Model $model): array
    {
        $attributes = $model->attributesToArray();
        $clean = [];

        foreach ($attributes as $key => $value) {
            if (in_array($key, ['password', 'remember_token'], true) || str_contains($key, 'secret') || str_contains($key, 'password')) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * @return list<class-string<Model>>
     */
    private function models(): array
    {
        return [
            TenantSetting::class,
            TenantBranding::class,
            TenantNotificationSetting::class,
            Domain::class,
            Membership::class,
            Location::class,
            ResourceCategory::class,
            Asset::class,
            Pool::class,
            AvailabilitySchedule::class,
            Blackout::class,
            Service::class,
            ServiceDuration::class,
            ResourceRequirement::class,
            Customer::class,
            Booking::class,
            Participant::class,
            BookingLineItem::class,
            Hold::class,
            Allocation::class,
            CapacityLedger::class,
            Departure::class,
            WaitlistEntry::class,
            PriceRule::class,
            PromoCode::class,
            AddOn::class,
            BookingAddOn::class,
            PricingSnapshot::class,
            Payment::class,
            PlatformFee::class,
            PlatformFeeRefund::class,
            Refund::class,
            StripeAccount::class,
            DisclosureVersion::class,
            WaiverVersion::class,
            WaiverSignature::class,
            MaintenanceWindow::class,
            MaintenanceRecord::class,
            Incident::class,
            RentalCheckout::class,
            RentalReturn::class,
            WidgetToken::class,
            NotificationHook::class,
            IntegrationOutbox::class,
            AuditLog::class,
        ];
    }
}
