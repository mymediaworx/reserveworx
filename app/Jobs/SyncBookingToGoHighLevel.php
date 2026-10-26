<?php

namespace App\Jobs;

use App\Domain\Fees\FeeChannel;
use App\Enums\TenantStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GhlConnection;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PricingSnapshot;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\Integrations\GhlContacts;
use App\Services\Integrations\GhlRateLimitPause;
use App\Services\Integrations\GhlRequestFailed;
use App\Services\Integrations\GhlSyncLogger;
use App\Services\Integrations\GoHighLevelClient;
use App\Support\BookingReference;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncBookingToGoHighLevel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 20;

    public int $rateLimitWaits = 0;

    public function __construct(
        public string $tenantId,
        public string $bookingId,
        public string $kind,
        public ?string $paymentId = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800];
    }

    public function handle(TenantManager $tenants, GoHighLevelClient $client, GhlContacts $contacts, GhlSyncLogger $logger): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant instanceof Tenant || $tenant->status === TenantStatus::Terminated) {
            $logger->write(
                tenantId: $this->tenantId,
                kind: $this->kind,
                attempt: $this->attempts(),
                status: 'skipped',
                locationId: null,
                bookingId: null,
                paymentId: null,
                httpStatus: null,
                error: 'Workspace is not active.',
                secret: null,
            );

            return;
        }

        $previous = $tenants->get();
        $tenants->set($tenant);

        try {
            $this->sync($client, $contacts, $logger);
        } finally {
            if ($previous instanceof Tenant) {
                $tenants->set($previous);
            } else {
                $tenants->forget();
            }
        }
    }

    private function sync(GoHighLevelClient $client, GhlContacts $contacts, GhlSyncLogger $logger): void
    {
        $connection = GhlConnection::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->first();

        if (! $connection instanceof GhlConnection || $connection->private_integration_token === '' || $connection->location_id === '') {
            $logger->write(
                tenantId: $this->tenantId,
                kind: $this->kind,
                attempt: $this->attempts(),
                status: 'skipped',
                locationId: $connection?->location_id,
                bookingId: null,
                paymentId: null,
                httpStatus: null,
                error: 'GoHighLevel is not linked.',
                secret: null,
            );

            return;
        }

        $token = $connection->private_integration_token;
        $locationId = $connection->location_id;
        $booking = Booking::withoutGlobalScope(TenantScope::class)->find($this->bookingId);

        if (! $booking instanceof Booking || $booking->tenant_id !== $this->tenantId) {
            $logger->write(
                tenantId: $this->tenantId,
                kind: $this->kind,
                attempt: $this->attempts(),
                status: 'failed',
                locationId: $locationId,
                bookingId: null,
                paymentId: null,
                httpStatus: null,
                error: 'Booking does not belong to this workspace.',
                secret: $token,
            );

            return;
        }

        $customer = $booking->customer_id === null
            ? null
            : Customer::withoutGlobalScope(TenantScope::class)->find($booking->customer_id);

        if (! $customer instanceof Customer) {
            $logger->write(
                tenantId: $this->tenantId,
                kind: $this->kind,
                attempt: $this->attempts(),
                status: 'skipped',
                locationId: $locationId,
                bookingId: $booking->id,
                paymentId: null,
                httpStatus: null,
                error: 'Booking has no customer to sync.',
                secret: $token,
            );

            return;
        }

        if ($customer->tenant_id !== $this->tenantId) {
            $logger->write(
                tenantId: $this->tenantId,
                kind: $this->kind,
                attempt: $this->attempts(),
                status: 'failed',
                locationId: $locationId,
                bookingId: $booking->id,
                paymentId: null,
                httpStatus: null,
                error: 'Customer does not belong to this workspace.',
                secret: $token,
            );

            return;
        }

        $email = is_string($customer->email) ? trim($customer->email) : '';
        $phone = is_string($customer->phone) ? trim($customer->phone) : '';

        if ($email === '' && $phone === '') {
            $logger->write(
                tenantId: $this->tenantId,
                kind: $this->kind,
                attempt: $this->attempts(),
                status: 'skipped',
                locationId: $locationId,
                bookingId: $booking->id,
                paymentId: null,
                httpStatus: null,
                error: 'Customer has no email or phone.',
                secret: $token,
            );

            return;
        }

        $paymentId = null;

        if ($this->paymentId !== null) {
            $payment = Payment::withoutGlobalScope(TenantScope::class)->find($this->paymentId);

            if (! $payment instanceof Payment || $payment->tenant_id !== $this->tenantId || $payment->booking_id !== $booking->id) {
                $logger->write(
                    tenantId: $this->tenantId,
                    kind: $this->kind,
                    attempt: $this->attempts(),
                    status: 'failed',
                    locationId: $locationId,
                    bookingId: $booking->id,
                    paymentId: null,
                    httpStatus: null,
                    error: 'Payment does not belong to this workspace.',
                    secret: $token,
                );

                return;
            }

            $paymentId = $payment->id;
        }

        $service = Service::withoutGlobalScope(TenantScope::class)->find($booking->service_id);
        $location = Location::withoutGlobalScope(TenantScope::class)->find($booking->location_id);
        $service = $service instanceof Service ? $service : null;
        $location = $location instanceof Location ? $location : null;

        try {
            $contactId = $contacts->resolve($token, $this->tenantId, $locationId, $customer->id, $this->contact($customer->name, $email, $phone));
            $client->addTags($token, $contactId, ['reserveworx', 'reserveworx-'.str_replace('.', '-', $this->kind)]);
            $client->addNote($token, $contactId, $this->note($booking, $customer, $service, $location, $paymentId));
        } catch (GhlRequestFailed $exception) {
            $pause = new GhlRateLimitPause;
            $decision = $pause->decide($exception, $this->rateLimitWaits, $this->backoff());
            $error = $exception->getMessage();
            $attempt = $this->attempts();

            if ($decision !== null) {
                $error = $decision['stopped']
                    ? GhlRateLimitPause::STOPPED
                    : $pause->waitingError($exception, $decision['seconds']);
                $attempt = $decision['stopped'] ? $this->tries : 1;
            }

            $logger->write(
                tenantId: $this->tenantId,
                kind: $this->kind,
                attempt: $attempt,
                status: 'failed',
                locationId: $locationId,
                bookingId: $booking->id,
                paymentId: $paymentId,
                httpStatus: $exception->httpStatus,
                error: $error,
                secret: $token,
            );

            if ($decision !== null) {
                if (! $decision['stopped']) {
                    $this->queueAfterRateLimit($decision['seconds']);
                }

                return;
            }

            throw $exception;
        }

        $logger->write(
            tenantId: $this->tenantId,
            kind: $this->kind,
            attempt: $this->attempts(),
            status: 'succeeded',
            locationId: $locationId,
            bookingId: $booking->id,
            paymentId: $paymentId,
            httpStatus: null,
            error: null,
            secret: $token,
        );
    }

    private function queueAfterRateLimit(int $seconds): void
    {
        $this->rateLimitWaits++;
        $next = new self($this->tenantId, $this->bookingId, $this->kind, $this->paymentId);
        $next->rateLimitWaits = $this->rateLimitWaits;
        dispatch($next)->onConnection('database')->delay(now()->addSeconds($seconds));
    }

    /**
     * @return array{email?: string, phone?: string, name?: string, firstName?: string, lastName?: string}
     */
    private function contact(?string $name, string $email, string $phone): array
    {
        $contact = [];

        if ($email !== '') {
            $contact['email'] = $email;
        }

        if ($phone !== '') {
            $contact['phone'] = $phone;
        }

        $trimmed = trim((string) $name);

        if ($trimmed === '') {
            return $contact;
        }

        $contact['name'] = $trimmed;
        $parts = preg_split('/\s+/', $trimmed, 2);
        $contact['firstName'] = is_array($parts) ? $parts[0] : $trimmed;

        if (is_array($parts) && isset($parts[1]) && $parts[1] !== '') {
            $contact['lastName'] = $parts[1];
        }

        return $contact;
    }

    private function note(Booking $booking, Customer $customer, ?Service $service, ?Location $location, ?string $paymentId): string
    {
        $timezone = $location instanceof Location && $location->timezone !== ''
            ? $location->timezone
            : 'UTC';
        $start = $booking->starts_at->copy()->timezone($timezone)->toDateTimeString();
        $serviceName = $service instanceof Service ? $service->name : '';
        $marker = 'reserveworx:'.$this->kind.':'.$booking->id.':'.($paymentId ?? '');

        return implode("\n", [
            'ReserveWorx '.$this->kind,
            'Booking '.$booking->id,
            'Reference '.BookingReference::short($booking->id),
            'Service '.$serviceName,
            'Status '.$booking->status->value,
            'Payment '.$booking->payment_status->value,
            'Starts '.$start.' '.$timezone,
            'Party '.$booking->participants,
            'Source ReserveWorx',
            'Attribution '.$this->attribution($booking),
            'Consent '.$this->consent($customer),
            $marker,
        ]);
    }

    private function attribution(Booking $booking): string
    {
        $snapshot = PricingSnapshot::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $booking->tenant_id)
            ->where('booking_id', $booking->id)
            ->first();

        if (! $snapshot instanceof PricingSnapshot) {
            return 'unrecorded';
        }

        $channel = $snapshot->payload['channel'] ?? null;

        if ($channel === FeeChannel::Direct->value || $channel === FeeChannel::ThirdParty->value) {
            return $channel;
        }

        return 'unrecorded';
    }

    private function consent(Customer $customer): string
    {
        return $customer->feedback_opted_out_at === null ? 'feedback allowed' : 'feedback opted out';
    }
}
