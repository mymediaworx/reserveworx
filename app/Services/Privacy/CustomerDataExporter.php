<?php

namespace App\Services\Privacy;

use App\Enums\CustomerDataExportStatus;
use App\Jobs\BuildCustomerDataExport;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingAddOn;
use App\Models\BookingLineItem;
use App\Models\BookingOperatorFee;
use App\Models\BookingReminder;
use App\Models\Customer;
use App\Models\CustomerDataExport;
use App\Models\Hold;
use App\Models\Incident;
use App\Models\IntegrationOutbox;
use App\Models\NotificationHook;
use App\Models\Participant;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\RentalCheckout;
use App\Models\RentalReturn;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WaiverSignature;
use App\Services\AuditLogger;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Queues a private JSON zip of one customer. The file is on the local disk,
 * which is not public. A signed URL is the only download.
 */
class CustomerDataExporter
{
    public const DOWNLOAD_MINUTES = 30;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantManager $tenants,
    ) {}

    public function queue(Customer $customer, User $actor, Request $request): CustomerDataExport
    {
        $this->assertTenant($customer->tenant_id);

        $export = CustomerDataExport::query()->create([
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'actor_user_id' => $actor->id,
            'disk' => CustomerDataExport::DISK,
            'status' => CustomerDataExportStatus::Queued,
        ]);

        $this->audit->log(
            action: 'customer.data_export_queued',
            subject: $export,
            properties: ['customer_id' => $customer->id],
            actor: $actor,
            request: $request,
        );

        try {
            BuildCustomerDataExport::dispatch($customer->tenant_id, $export->id);
        } catch (\Throwable) {
            // A synchronous worker records the failure on the export row.
        }

        $fresh = $export->fresh();

        return $fresh instanceof CustomerDataExport ? $fresh : $export;
    }

    public function write(string $exportId): void
    {
        $export = CustomerDataExport::query()->whereKey($exportId)->first();
        $tenantId = $this->tenants->id();

        if (! $export instanceof CustomerDataExport || $tenantId === null || $export->tenant_id !== $tenantId) {
            throw new CustomerExportException('The export is not in this workspace.');
        }

        if ($export->status === CustomerDataExportStatus::Completed && $this->fileReady($export)) {
            return;
        }

        $customer = Customer::query()->whereKey($export->customer_id)->first();

        if (! $customer instanceof Customer || $customer->tenant_id !== $tenantId) {
            throw new CustomerExportException('The customer is not in this workspace.');
        }

        $files = $this->files($customer, $tenantId);
        $path = 'customer-exports/'.$tenantId.'/'.$export->id.'.zip';
        $bytes = $this->storeZip($path, $files);

        try {
            $export->forceFill([
                'disk' => CustomerDataExport::DISK,
                'path' => $path,
                'bytes' => $bytes,
                'row_counts' => $this->counts($files),
                'status' => CustomerDataExportStatus::Completed,
                'completed_at' => now(),
                'failed_at' => null,
            ])->save();
        } catch (\Throwable $exception) {
            Storage::disk(CustomerDataExport::DISK)->delete($path);

            throw $exception;
        }

        $actor = User::query()->find($export->actor_user_id);

        $this->audit->log(
            action: 'customer.data_exported',
            subject: $export,
            properties: [
                'customer_id' => $customer->id,
                'bytes' => $export->bytes,
                'row_counts' => $export->row_counts,
            ],
            tenantId: $tenantId,
            actor: $actor instanceof User ? $actor : null,
        );
    }

    public function markFailed(string $exportId): void
    {
        $export = CustomerDataExport::withoutGlobalScope(TenantScope::class)->whereKey($exportId)->first();

        if (! $export instanceof CustomerDataExport || $export->status === CustomerDataExportStatus::Completed) {
            return;
        }

        if (is_string($export->path) && $export->path !== '') {
            Storage::disk($export->disk)->delete($export->path);
        }

        $export->forceFill([
            'status' => CustomerDataExportStatus::Failed,
            'path' => null,
            'bytes' => null,
            'completed_at' => null,
            'failed_at' => now(),
        ])->save();
    }

    public function readyExportId(Customer $customer): ?string
    {
        $exports = CustomerDataExport::query()
            ->where('customer_id', $customer->id)
            ->where('status', CustomerDataExportStatus::Completed)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get();

        foreach ($exports as $export) {
            if ($this->fileReady($export)) {
                return $export->id;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     id: string,
     *     customer_id: string,
     *     status: string,
     *     bytes: int|null,
     *     row_counts: array<string, int>|null,
     *     completed_at: string|null,
     *     download_url: string|null,
     *     download_expires_at: string|null
     * }
     */
    public function payload(Customer $customer, CustomerDataExport $export): array
    {
        $download = $this->downloadUrl($customer, $export);

        /** @var array<string, int>|null $counts */
        $counts = is_array($export->row_counts) ? $export->row_counts : null;

        return [
            'id' => $export->id,
            'customer_id' => $export->customer_id,
            'status' => $export->status->value,
            'bytes' => $export->bytes,
            'row_counts' => $counts,
            'completed_at' => $export->completed_at?->toIso8601String(),
            'download_url' => $download['url'],
            'download_expires_at' => $download['expires_at'],
        ];
    }

    public function download(Customer $customer, CustomerDataExport $export): StreamedResponse
    {
        if ($export->customer_id !== $customer->id || $export->tenant_id !== $customer->tenant_id || ! $this->fileReady($export)) {
            abort(404);
        }

        return Storage::disk($export->disk)->download(
            (string) $export->path,
            'customer-'.$customer->id.'.zip',
            ['Content-Type' => 'application/zip'],
        );
    }

    /**
     * @return array{url: string|null, expires_at: string|null}
     */
    private function downloadUrl(Customer $customer, CustomerDataExport $export): array
    {
        if ($export->customer_id !== $customer->id || ! $this->fileReady($export)) {
            return ['url' => null, 'expires_at' => null];
        }

        $expires = now()->addMinutes(self::DOWNLOAD_MINUTES);

        return [
            'url' => URL::temporarySignedRoute('customers.exports.download', $expires, [
                'customer' => $customer->id,
                'export' => $export->id,
            ]),
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    private function fileReady(CustomerDataExport $export): bool
    {
        if ($export->status !== CustomerDataExportStatus::Completed || ! is_string($export->path) || $export->path === '') {
            return false;
        }

        $prefix = 'customer-exports/'.$export->tenant_id.'/';

        return str_starts_with($export->path, $prefix)
            && ! str_contains($export->path, '..')
            && Storage::disk($export->disk)->exists($export->path);
    }

    /**
     * @return array<string, mixed>
     */
    private function files(Customer $customer, string $tenantId): array
    {
        $bookings = Booking::query()->where('customer_id', $customer->id)->orderBy('id')->get();
        $bookingIds = [];

        foreach ($bookings as $booking) {
            $bookingIds[] = $booking->id;
        }

        $fees = $this->modelsForBookings(PlatformFee::class, $bookingIds, $tenantId);
        $feeIds = [];

        foreach ($fees as $fee) {
            $id = $fee['id'] ?? null;

            if (is_string($id)) {
                $feeIds[] = $id;
            }
        }

        $participants = $this->modelsForBookings(Participant::class, $bookingIds, $tenantId);
        $signatures = $this->modelsForBookings(WaiverSignature::class, $bookingIds, $tenantId);
        $payments = $this->modelsForBookings(Payment::class, $bookingIds, $tenantId);
        $refunds = $this->modelsForBookings(Refund::class, $bookingIds, $tenantId);
        $waitlist = $this->waitlist($customer, $bookingIds, $tenantId);

        $subjectIds = array_values(array_unique(array_filter(array_merge(
            [$customer->id],
            $bookingIds,
            $this->ids($participants),
            $this->ids($signatures),
            $this->ids($payments),
            $this->ids($refunds),
            $this->ids($waitlist),
        ), is_string(...))));

        return [
            'manifest' => [
                'exported_at' => now()->utc()->toIso8601String(),
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'waiver_retention' => 'open',
                'note' => 'Signed waiver rows are included. Spec open question 3 does not set how long they are kept, so a later anonymization keeps the rows and clears only direct identifiers.',
            ],
            'customer' => $this->attributes($customer, $tenantId),
            'bookings' => $this->rows($bookings, $tenantId),
            'participants' => $participants,
            'waiver_signatures' => $signatures,
            'payments' => $payments,
            'refunds' => $refunds,
            'platform_fees' => $fees,
            'platform_fee_refunds' => $this->feeRefunds($feeIds, $tenantId),
            'pricing_snapshots' => $this->modelsForBookings(PricingSnapshot::class, $bookingIds, $tenantId),
            'booking_line_items' => $this->modelsForBookings(BookingLineItem::class, $bookingIds, $tenantId),
            'booking_add_ons' => $this->modelsForBookings(BookingAddOn::class, $bookingIds, $tenantId),
            'booking_operator_fees' => $this->modelsForBookings(BookingOperatorFee::class, $bookingIds, $tenantId),
            'booking_reminders' => $this->modelsForBookings(BookingReminder::class, $bookingIds, $tenantId),
            'incidents' => $this->modelsForBookings(Incident::class, $bookingIds, $tenantId),
            'rental_checkouts' => $this->modelsForBookings(RentalCheckout::class, $bookingIds, $tenantId),
            'rental_returns' => $this->modelsForBookings(RentalReturn::class, $bookingIds, $tenantId),
            'allocations' => $this->modelsForBookings(Allocation::class, $bookingIds, $tenantId),
            'holds' => $this->modelsForBookings(Hold::class, $bookingIds, $tenantId),
            'notification_hooks' => $this->modelsForBookings(NotificationHook::class, $bookingIds, $tenantId),
            'integration_outbox' => $this->outbox($bookingIds, $tenantId),
            'waitlist_entries' => $waitlist,
            'audit_logs' => $this->audits($subjectIds, $tenantId),
        ];
    }

    /**
     * @param  array<string, mixed>  $files
     * @return array<string, int>
     */
    private function counts(array $files): array
    {
        $counts = [];

        foreach ($files as $name => $value) {
            if ($name === 'manifest' || ! is_array($value)) {
                continue;
            }

            $counts[$name] = array_is_list($value) ? count($value) : 1;
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $files
     */
    private function storeZip(string $path, array $files): int
    {
        $temporary = tempnam(sys_get_temp_dir(), 'customer-export-');

        if ($temporary === false) {
            throw new CustomerExportException('The export file could not be written.');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            unlink($temporary);

            throw new CustomerExportException('The export file could not be written.');
        }

        try {
            foreach ($files as $name => $value) {
                $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

                if ($zip->addFromString($name.'.json', $encoded) !== true) {
                    throw new CustomerExportException('The export file could not be written.');
                }
            }

            if ($zip->close() !== true) {
                throw new CustomerExportException('The export file could not be written.');
            }

            $contents = file_get_contents($temporary);

            if ($contents === false || ! Storage::disk(CustomerDataExport::DISK)->put($path, $contents)) {
                throw new CustomerExportException('The export file could not be written.');
            }

            return strlen($contents);
        } catch (\Throwable $exception) {
            $zip->close();
            Storage::disk(CustomerDataExport::DISK)->delete($path);

            throw $exception;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * @param  class-string<Model>  $class
     * @param  list<string>  $bookingIds
     * @return list<array<string, mixed>>
     */
    private function modelsForBookings(string $class, array $bookingIds, string $tenantId): array
    {
        if ($bookingIds === []) {
            return [];
        }

        return $this->rows($class::query()->whereIn('booking_id', $bookingIds)->orderBy('id')->get(), $tenantId);
    }

    /**
     * @param  list<string>  $feeIds
     * @return list<array<string, mixed>>
     */
    private function feeRefunds(array $feeIds, string $tenantId): array
    {
        if ($feeIds === []) {
            return [];
        }

        return $this->rows(
            PlatformFeeRefund::query()->whereIn('platform_fee_id', $feeIds)->orderBy('id')->get(),
            $tenantId,
        );
    }

    /**
     * @param  list<string>  $bookingIds
     * @return list<array<string, mixed>>
     */
    private function waitlist(Customer $customer, array $bookingIds, string $tenantId): array
    {
        $email = is_string($customer->email) ? mb_strtolower($customer->email) : '';

        if ($bookingIds === [] && $email === '') {
            return [];
        }

        $entries = WaitlistEntry::query()
            ->where(function ($query) use ($bookingIds, $email): void {
                if ($bookingIds !== []) {
                    $query->whereIn('booking_id', $bookingIds);
                }

                if ($email !== '') {
                    $method = $bookingIds === [] ? 'whereRaw' : 'orWhereRaw';
                    $query->{$method}('LOWER(customer_email) = ?', [$email]);
                }
            })
            ->orderBy('id')
            ->get();

        return $this->rows($entries, $tenantId);
    }

    /**
     * @param  list<string>  $bookingIds
     * @return list<array<string, mixed>>
     */
    private function outbox(array $bookingIds, string $tenantId): array
    {
        if ($bookingIds === []) {
            return [];
        }

        $rows = [];

        foreach (IntegrationOutbox::query()->orderBy('id')->get() as $row) {
            $bookingId = $row->payload['booking_id'] ?? null;

            if (! is_string($bookingId) || ! in_array($bookingId, $bookingIds, true)) {
                continue;
            }

            $rows[] = $this->attributes($row, $tenantId);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $subjectIds
     * @return list<array<string, mixed>>
     */
    private function audits(array $subjectIds, string $tenantId): array
    {
        if ($subjectIds === []) {
            return [];
        }

        return $this->rows(
            AuditLog::query()->whereIn('subject_id', $subjectIds)->orderBy('id')->get(),
            $tenantId,
        );
    }

    /**
     * @param  iterable<Model>  $models
     * @return list<array<string, mixed>>
     */
    private function rows(iterable $models, string $tenantId): array
    {
        $rows = [];

        foreach ($models as $model) {
            $rows[] = $this->attributes($model, $tenantId);
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function ids(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;

            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Model $model, string $tenantId): array
    {
        $owner = $model->getAttribute('tenant_id');

        if ($owner !== $tenantId) {
            throw new CustomerExportException('A row from another workspace was read.');
        }

        return $model->attributesToArray();
    }

    private function assertTenant(?string $tenantId): void
    {
        if ($tenantId === null || $this->tenants->id() !== $tenantId) {
            throw new CustomerExportException('The customer is not in this workspace.');
        }
    }
}
