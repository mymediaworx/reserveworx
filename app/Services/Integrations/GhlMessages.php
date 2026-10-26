<?php

namespace App\Services\Integrations;

use App\Enums\BookingStatus;
use App\Enums\GhlMessageKind;
use App\Enums\TenantStatus;
use App\Jobs\SendGhlBookingMessage;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GhlConnection;
use App\Models\GhlMessageLog;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\RentalCheckout;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\Notifications\TransactionalEmailPreferences;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Queues the booking communications spec Section 10 sends through GoHighLevel.
 * A workspace with no link returns false so the caller can use email instead.
 * The private integration token is never written to the log row or the job.
 */
final class GhlMessages
{
    public function __construct(
        private readonly TransactionalEmailPreferences $preferences,
        private readonly GoHighLevelClient $client,
        private readonly GhlSyncLogger $logger,
        private readonly GhlBookingNotice $notices,
        private readonly TenantManager $tenants,
    ) {}

    public function queueBooking(Booking $booking, GhlMessageKind $kind): bool
    {
        return $this->queue(
            $booking->tenant_id,
            $booking->id,
            $kind,
            $kind->value.':'.$booking->id,
        );
    }

    public function queueReceipt(Payment $payment): bool
    {
        return $this->queue(
            $payment->tenant_id,
            $payment->booking_id,
            GhlMessageKind::Receipt,
            GhlMessageKind::Receipt->value.':'.$payment->id,
            $payment->id,
        );
    }

    public function queueReminder(Booking $booking, int $offsetMinutes): bool
    {
        return $this->queue(
            $booking->tenant_id,
            $booking->id,
            GhlMessageKind::Reminder,
            GhlMessageKind::Reminder->value.':'.$booking->id.':'.$offsetMinutes,
            offsetMinutes: $offsetMinutes,
        );
    }

    public function queueRefund(Refund $refund): bool
    {
        return $this->queue(
            $refund->tenant_id,
            $refund->booking_id,
            GhlMessageKind::RefundIssued,
            GhlMessageKind::RefundIssued->value.':'.$refund->id,
            refundId: $refund->id,
        );
    }

    public function queueOverdue(RentalCheckout $checkout): bool
    {
        return $this->queue(
            $checkout->tenant_id,
            $checkout->booking_id,
            GhlMessageKind::Overdue,
            GhlMessageKind::Overdue->value.':'.$checkout->id,
            checkoutId: $checkout->id,
        );
    }

    public function deliver(string $tenantId, string $logId, int $attempt): void
    {
        $log = $this->log($tenantId, $logId);

        if (! $log instanceof GhlMessageLog || in_array($log->status, ['succeeded', 'skipped'], true)) {
            return;
        }

        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant || $tenant->status === TenantStatus::Terminated) {
            $this->finish($log, 'skipped', $attempt, null, 'Workspace is not active.', null, null, null, null);

            return;
        }

        $previous = $this->tenants->get();
        $this->tenants->set($tenant);

        try {
            $this->send($log, $attempt);
        } finally {
            if ($previous instanceof Tenant) {
                $this->tenants->set($previous);
            } else {
                $this->tenants->forget();
            }
        }
    }

    public function markExhausted(string $tenantId, string $logId, ?Throwable $exception): void
    {
        $log = $this->log($tenantId, $logId);

        if (! $log instanceof GhlMessageLog || in_array($log->status, ['succeeded', 'skipped'], true)) {
            return;
        }

        $connection = GhlConnection::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->first();
        $secret = $connection instanceof GhlConnection ? $connection->private_integration_token : null;
        $message = $exception instanceof GhlRequestFailed
            ? $exception->getMessage()
            : 'GoHighLevel message stopped after the last try.';

        $this->finish($log, 'failed', max($log->attempts, 1), $log->http_status, $message, $secret, $log->channel, null, $log->location_id);
    }

    private function queue(
        string $tenantId,
        string $bookingId,
        GhlMessageKind $kind,
        string $dedupe,
        ?string $paymentId = null,
        ?string $refundId = null,
        ?string $checkoutId = null,
        ?int $offsetMinutes = null,
    ): bool {
        if (! $this->linked($tenantId)) {
            return false;
        }

        $booking = Booking::withoutGlobalScope(TenantScope::class)->find($bookingId);

        if (! $booking instanceof Booking || $booking->tenant_id !== $tenantId) {
            return false;
        }

        if (! $this->preferences->enabled($tenantId, $kind->preference())) {
            $this->open($tenantId, $booking->id, $kind, $dedupe, 'skipped', 'The workspace turned this message off.', $paymentId, $refundId, $checkoutId, $offsetMinutes);

            return false;
        }

        $customer = $this->customer($booking);

        if (! $customer instanceof Customer || ! $this->reachable($customer)) {
            $this->open($tenantId, $booking->id, $kind, $dedupe, 'skipped', 'Customer has no email or phone.', $paymentId, $refundId, $checkoutId, $offsetMinutes);

            return false;
        }

        if ($paymentId !== null && $this->ownedId(Payment::class, $paymentId, $tenantId, $bookingId) === null) {
            return false;
        }

        if ($refundId !== null && $this->ownedId(Refund::class, $refundId, $tenantId, $bookingId) === null) {
            return false;
        }

        if ($checkoutId !== null && $this->ownedId(RentalCheckout::class, $checkoutId, $tenantId, $bookingId) === null) {
            return false;
        }

        $log = $this->open($tenantId, $booking->id, $kind, $dedupe, 'queued', null, $paymentId, $refundId, $checkoutId, $offsetMinutes);

        if (! $log instanceof GhlMessageLog || ! $log->wasRecentlyCreated) {
            return $log instanceof GhlMessageLog && $log->status !== 'skipped';
        }

        if ($log->status !== 'queued') {
            return false;
        }

        SendGhlBookingMessage::dispatch($tenantId, $log->id);

        return true;
    }

    private function send(GhlMessageLog $log, int $attempt): void
    {
        $kind = GhlMessageKind::tryFrom($log->kind);
        $connection = GhlConnection::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $log->tenant_id)
            ->first();

        if (! $kind instanceof GhlMessageKind || ! $connection instanceof GhlConnection || $connection->private_integration_token === '' || $connection->location_id === '') {
            $this->finish($log, 'skipped', $attempt, null, 'GoHighLevel is not linked.', null, null, null, $connection?->location_id);

            return;
        }

        $token = $connection->private_integration_token;
        $booking = Booking::withoutGlobalScope(TenantScope::class)->find($log->booking_id);

        if (! $booking instanceof Booking || $booking->tenant_id !== $log->tenant_id) {
            $this->finish($log, 'failed', $attempt, null, 'Booking does not belong to this workspace.', $token, null, null, $connection->location_id);

            return;
        }

        $customer = $this->customer($booking);

        if (! $customer instanceof Customer) {
            $this->finish($log, 'skipped', $attempt, null, 'Customer has no email or phone.', $token, null, null, $connection->location_id);

            return;
        }

        if ($customer->tenant_id !== $log->tenant_id) {
            $this->finish($log, 'failed', $attempt, null, 'Customer does not belong to this workspace.', $token, null, null, $connection->location_id);

            return;
        }

        if (! $this->preferences->enabled($log->tenant_id, $kind->preference())) {
            $this->finish($log, 'skipped', $attempt, null, 'The workspace turned this message off.', $token, null, null, $connection->location_id);

            return;
        }

        $payment = $this->payment($log, $booking);
        $refund = $this->refund($log, $booking);
        $checkout = $this->checkout($log, $booking);

        if ($payment === false || $refund === false || $checkout === false || ! $this->stillDue($kind, $booking, $checkout)) {
            $error = $payment === false
                ? 'Payment does not belong to this workspace.'
                : ($refund === false
                    ? 'Refund does not belong to this workspace.'
                    : ($checkout === false
                        ? 'Checkout does not belong to this workspace.'
                        : 'This message is no longer due.'));
            $status = $payment === false || $refund === false || $checkout === false ? 'failed' : 'skipped';
            $this->finish($log, $status, $attempt, null, $error, $token, null, null, $connection->location_id);

            return;
        }

        if (! $this->reachable($customer)) {
            $this->finish($log, 'skipped', $attempt, null, 'Customer has no email or phone.', $token, null, null, $connection->location_id);

            return;
        }

        $service = Service::withoutGlobalScope(TenantScope::class)->find($booking->service_id);
        $location = Location::withoutGlobalScope(TenantScope::class)->find($booking->location_id);
        $serviceName = $service instanceof Service && $service->tenant_id === $log->tenant_id ? trim($service->name) : '';
        $timezone = $location instanceof Location && $location->tenant_id === $log->tenant_id && in_array($location->timezone, timezone_identifiers_list(), true)
            ? $location->timezone
            : 'UTC';
        $snapshot = PricingSnapshot::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $log->tenant_id)
            ->where('booking_id', $booking->id)
            ->first();
        $payload = $snapshot instanceof PricingSnapshot ? $snapshot->payload : [];
        $notice = $this->notices->compose(
            $kind,
            $booking,
            $customer,
            $serviceName,
            $timezone,
            $payment instanceof Payment ? $payment : null,
            $refund instanceof Refund ? $refund : null,
            $checkout instanceof RentalCheckout ? $checkout : null,
            $log->offset_minutes,
            $payload,
        );

        try {
            $contactId = $this->client->upsertContact($token, $connection->location_id, $this->contact($customer->name, $notice['email'], $notice['phone']));
            $messageId = $this->client->sendMessage($token, $this->payload($notice, $contactId));
        } catch (GhlRequestFailed $exception) {
            $this->finish($log, 'failed', $attempt, $exception->httpStatus, $exception->getMessage(), $token, $notice['channel'], null, $connection->location_id);

            throw $exception;
        }

        $this->finish($log, 'succeeded', $attempt, null, null, $token, $notice['channel'], $messageId, $connection->location_id);
    }

    private function linked(string $tenantId): bool
    {
        return GhlConnection::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('location_id', '!=', '')
            ->exists();
    }

    private function open(
        string $tenantId,
        string $bookingId,
        GhlMessageKind $kind,
        string $dedupe,
        string $status,
        ?string $error,
        ?string $paymentId,
        ?string $refundId,
        ?string $checkoutId,
        ?int $offsetMinutes,
    ): ?GhlMessageLog {
        try {
            return GhlMessageLog::query()->create([
                'tenant_id' => $tenantId,
                'booking_id' => $bookingId,
                'payment_id' => $this->ownedId(Payment::class, $paymentId, $tenantId, $bookingId),
                'refund_id' => $this->ownedId(Refund::class, $refundId, $tenantId, $bookingId),
                'checkout_id' => $this->ownedId(RentalCheckout::class, $checkoutId, $tenantId, $bookingId),
                'kind' => $kind->value,
                'dedupe_key' => $dedupe,
                'status' => $status,
                'attempts' => 0,
                'error' => $this->logger->redact($error, null),
                'offset_minutes' => $offsetMinutes,
            ]);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
        }

        return GhlMessageLog::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('dedupe_key', $dedupe)
            ->first();
    }

    /**
     * @param  class-string<Payment|Refund|RentalCheckout>  $class
     */
    private function ownedId(string $class, ?string $id, string $tenantId, string $bookingId): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        $row = $class::withoutGlobalScope(TenantScope::class)->find($id);

        if ($row === null || $row->getAttribute('tenant_id') !== $tenantId || $row->getAttribute('booking_id') !== $bookingId) {
            return null;
        }

        return $id;
    }

    private function log(string $tenantId, string $logId): ?GhlMessageLog
    {
        return GhlMessageLog::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereKey($logId)
            ->first();
    }

    private function customer(Booking $booking): ?Customer
    {
        if ($booking->customer_id === null) {
            return null;
        }

        $customer = Customer::withoutGlobalScope(TenantScope::class)->find($booking->customer_id);

        return $customer instanceof Customer ? $customer : null;
    }

    private function reachable(Customer $customer): bool
    {
        $email = is_string($customer->email) ? trim($customer->email) : '';
        $phone = is_string($customer->phone) ? trim($customer->phone) : '';

        return $email !== '' || $phone !== '';
    }

    private function payment(GhlMessageLog $log, Booking $booking): Payment|false|null
    {
        if ($log->payment_id === null) {
            return null;
        }

        $payment = Payment::withoutGlobalScope(TenantScope::class)->find($log->payment_id);

        if (! $payment instanceof Payment || $payment->tenant_id !== $log->tenant_id || $payment->booking_id !== $booking->id || $payment->status !== 'paid') {
            return false;
        }

        return $payment;
    }

    private function refund(GhlMessageLog $log, Booking $booking): Refund|false|null
    {
        if ($log->refund_id === null) {
            return null;
        }

        $refund = Refund::withoutGlobalScope(TenantScope::class)->find($log->refund_id);

        if (! $refund instanceof Refund || $refund->tenant_id !== $log->tenant_id || $refund->booking_id !== $booking->id) {
            return false;
        }

        return $refund;
    }

    private function checkout(GhlMessageLog $log, Booking $booking): RentalCheckout|false|null
    {
        if ($log->checkout_id === null) {
            return null;
        }

        $checkout = RentalCheckout::withoutGlobalScope(TenantScope::class)->find($log->checkout_id);

        if (! $checkout instanceof RentalCheckout || $checkout->tenant_id !== $log->tenant_id || $checkout->booking_id !== $booking->id) {
            return false;
        }

        return $checkout;
    }

    private function stillDue(GhlMessageKind $kind, Booking $booking, RentalCheckout|false|null $checkout): bool
    {
        return match ($kind) {
            GhlMessageKind::Confirmed => $booking->status === BookingStatus::Confirmed,
            GhlMessageKind::Reminder => $booking->status === BookingStatus::Confirmed
                && $booking->starts_at->greaterThan(now()),
            GhlMessageKind::Cancelled, GhlMessageKind::GuestCancelled => $booking->status === BookingStatus::Cancelled,
            GhlMessageKind::Receipt, GhlMessageKind::RefundIssued => true,
            GhlMessageKind::Overdue => $checkout instanceof RentalCheckout && $checkout->returned_at === null,
        };
    }

    /**
     * @param  array{channel: string, subject: string, text: string, email: string, phone: string}  $notice
     * @return array{type: string, contactId: string, message: string, status: string, subject?: string, emailTo?: string, toNumber?: string}
     */
    private function payload(array $notice, string $contactId): array
    {
        $payload = [
            'type' => $notice['channel'] === 'sms' ? 'SMS' : 'Email',
            'contactId' => $contactId,
            'message' => $notice['text'],
            'status' => 'pending',
        ];

        if ($notice['channel'] === 'email') {
            $payload['subject'] = $notice['subject'];
            $payload['emailTo'] = $notice['email'];
        } else {
            $payload['toNumber'] = $notice['phone'];
        }

        return $payload;
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

    private function finish(
        GhlMessageLog $log,
        string $status,
        int $attempt,
        ?int $httpStatus,
        ?string $error,
        ?string $secret,
        ?string $channel,
        ?string $messageId,
        ?string $locationId,
    ): void {
        if (! in_array($status, ['succeeded', 'failed', 'skipped'], true)) {
            $status = 'failed';
        }

        $log->status = $status;
        $log->attempts = max(1, $attempt);
        $log->http_status = $httpStatus;
        $log->error = $this->logger->redact($error, $secret);
        $log->location_id = $locationId;

        if (is_string($channel) && $channel !== '') {
            $log->channel = $channel;
        }

        if (is_string($messageId) && $messageId !== '') {
            $log->ghl_message_id = $messageId;
        }

        $log->save();
    }
}
