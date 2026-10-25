<?php

namespace App\Services\Privacy;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerDataDeletion;
use App\Models\CustomerSignInToken;
use App\Models\FeedbackRequest;
use App\Models\Incident;
use App\Models\IntegrationOutbox;
use App\Models\NotificationHook;
use App\Models\Participant;
use App\Models\RentalReturn;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WaiverSignature;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Removes direct identifiers for one customer. Booking, payment, refund, and
 * fee rows stay so accounting and later refunds still have a record. Signed
 * waivers stay because the retention period is an open question.
 */
class CustomerDataEraser
{
    public const REMOVED_CUSTOMER = 'Removed customer';

    public const REMOVED_PERSON = 'Removed';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CustomerDataExporter $exports,
    ) {}

    public function erase(Customer $customer, User $actor, bool $confirm, ?Request $request = null): CustomerErasureResult
    {
        $result = DB::transaction(function () use ($customer, $actor, $confirm): array {
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->first();

            if (! $locked instanceof Customer) {
                return ['missing' => true];
            }

            if ($locked->anonymized_at !== null) {
                return [
                    'missing' => false,
                    'refused' => false,
                    'idempotent' => true,
                    'customer' => $locked,
                    'deletion' => $this->existingDeletion($locked, $actor),
                    'waiver_rows_kept' => 0,
                    'export_id' => null,
                ];
            }

            $exportId = $this->exports->readyExportId($locked);

            if ($exportId === null && $confirm !== true) {
                return [
                    'missing' => false,
                    'refused' => true,
                    'idempotent' => false,
                    'customer' => $locked,
                    'deletion' => null,
                    'waiver_rows_kept' => 0,
                    'export_id' => null,
                ];
            }

            $bookingIds = Booking::query()->where('customer_id', $locked->id)->orderBy('id')->pluck('id')->all();
            $bookingIds = array_values(array_filter($bookingIds, is_string(...)));
            $names = [];
            $fragments = [];
            $this->remember($names, $fragments, $locked->name, $locked->email, $locked->phone);

            $participants = $bookingIds === []
                ? new Collection
                : Participant::query()->whereIn('booking_id', $bookingIds)->orderBy('id')->get();
            $signatures = $bookingIds === []
                ? new Collection
                : WaiverSignature::query()->whereIn('booking_id', $bookingIds)->orderBy('id')->get();
            $waitlist = $this->waitlist($locked, $bookingIds);

            foreach ($participants as $participant) {
                $this->remember($names, $fragments, $participant->name, null, null);
                $this->remember($names, $fragments, $participant->guardian_name, null, null);
            }

            foreach ($signatures as $signature) {
                $this->remember($names, $fragments, $signature->participant_name, null, null);
                $this->remember($names, $fragments, $signature->guardian_name, null, null);
            }

            foreach ($waitlist as $entry) {
                $this->remember($names, $fragments, $entry->customer_name, $entry->customer_email, $entry->customer_phone);
            }

            $account = $this->lockedAccount($locked);

            if ($account instanceof CustomerAccount) {
                $this->remember($names, $fragments, $account->name, $account->email, null);
            }

            if ($bookingIds !== []) {
                Participant::query()->whereIn('booking_id', $bookingIds)->update([
                    'name' => self::REMOVED_PERSON,
                    'guardian_name' => null,
                    'updated_at' => now(),
                ]);
                WaiverSignature::query()->whereIn('booking_id', $bookingIds)->update([
                    'participant_name' => self::REMOVED_PERSON,
                    'guardian_name' => null,
                    'ip_address' => null,
                    'user_agent' => null,
                    'updated_at' => now(),
                ]);
                $this->scrubIncidents($bookingIds, $names, $fragments);
                $this->scrubReturns($bookingIds, $names, $fragments);
                $this->scrubStaffNotes($bookingIds, $names, $fragments);
                $this->scrubHooks($bookingIds, $names, $fragments);
                $this->scrubOutbox($bookingIds, $names, $fragments);
            }

            if ($waitlist->isNotEmpty()) {
                WaitlistEntry::query()->whereIn('id', $waitlist->modelKeys())->update([
                    'customer_name' => null,
                    'customer_email' => null,
                    'customer_phone' => null,
                    'updated_at' => now(),
                ]);
            }

            $feedbackIds = $bookingIds === []
                ? []
                : FeedbackRequest::query()->whereIn('booking_id', $bookingIds)->orderBy('id')->pluck('id')->all();
            $subjectIds = array_values(array_unique(array_filter(array_merge(
                [$locked->id, $account?->id],
                $bookingIds,
                $participants->modelKeys(),
                $signatures->modelKeys(),
                $waitlist->modelKeys(),
                $feedbackIds,
            ), is_string(...))));
            $this->scrubFeedback($bookingIds, $names, $fragments);
            $this->scrubAudits($subjectIds, $fragments, $names);

            $anonymizedAt = now();
            $locked->forceFill([
                'name' => self::REMOVED_CUSTOMER,
                'email' => null,
                'phone' => null,
                'customer_account_id' => null,
                'anonymized_at' => $anonymizedAt,
            ])->save();

            if ($account instanceof CustomerAccount) {
                $this->releaseAccount($account, $locked);
            }

            $deletion = CustomerDataDeletion::query()->create([
                'tenant_id' => $locked->tenant_id,
                'customer_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'export_id' => $exportId,
                'confirmed' => $confirm,
                'anonymized_at' => $anonymizedAt,
            ]);

            return [
                'missing' => false,
                'refused' => false,
                'idempotent' => false,
                'customer' => $locked,
                'deletion' => $deletion,
                'waiver_rows_kept' => $signatures->count(),
                'export_id' => $exportId,
            ];
        });

        if ($result['missing'] === true) {
            abort(404);
        }

        $saved = $result['customer'];

        if ($result['refused'] === true) {
            $this->audit->log(
                action: 'customer.data_deletion_refused',
                subject: $saved,
                properties: ['reason' => 'data_export_not_available'],
                actor: $actor,
                request: $request,
            );

            return new CustomerErasureResult(true, false, null, $saved);
        }

        $deletion = $result['deletion'];
        $idempotent = $result['idempotent'];

        $this->audit->log(
            action: $idempotent ? 'customer.data_anonymized_repeated' : 'customer.data_anonymized',
            subject: $deletion,
            properties: $idempotent
                ? ['customer_id' => $saved->id, 'idempotent' => true]
                : [
                    'customer_id' => $saved->id,
                    'idempotent' => false,
                    'export_id' => $result['export_id'],
                    'confirmed' => $confirm,
                    'waiver_rows_kept' => $result['waiver_rows_kept'],
                    'waiver_retention' => 'open',
                ],
            actor: $actor,
            request: $request,
        );

        return new CustomerErasureResult(false, $idempotent, $deletion, $saved->fresh() ?? $saved);
    }

    private function existingDeletion(Customer $customer, User $actor): CustomerDataDeletion
    {
        $existing = CustomerDataDeletion::query()->where('customer_id', $customer->id)->first();

        if ($existing instanceof CustomerDataDeletion) {
            return $existing;
        }

        return CustomerDataDeletion::query()->create([
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'actor_user_id' => $actor->id,
            'export_id' => null,
            'confirmed' => false,
            'anonymized_at' => $customer->anonymized_at ?? now(),
        ]);
    }

    private function lockedAccount(Customer $customer): ?CustomerAccount
    {
        $accountId = $customer->customer_account_id;

        if (! is_string($accountId) || $accountId === '') {
            return null;
        }

        $account = CustomerAccount::query()->whereKey($accountId)->lockForUpdate()->first();

        if (! $account instanceof CustomerAccount || $account->tenant_id !== $customer->tenant_id) {
            return null;
        }

        return $account;
    }

    /**
     * Drops the sign-in when this customer was the last live row on it.
     * Another customer in the workspace that still uses the account keeps it.
     */
    private function releaseAccount(CustomerAccount $account, Customer $customer): void
    {
        $shared = Customer::query()
            ->where('customer_account_id', $account->id)
            ->whereNull('anonymized_at')
            ->whereKeyNot($customer->id)
            ->exists();

        if ($shared) {
            return;
        }

        CustomerSignInToken::query()->where('customer_account_id', $account->id)->delete();
        $account->tokens()->delete();
        $account->delete();
    }

    /**
     * @param  list<string>  $bookingIds
     * @return Collection<int, WaitlistEntry>
     */
    private function waitlist(Customer $customer, array $bookingIds): Collection
    {
        $email = is_string($customer->email) ? mb_strtolower($customer->email) : '';

        if ($bookingIds === [] && $email === '') {
            return new Collection;
        }

        return WaitlistEntry::query()
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
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $fragments
     */
    private function remember(array &$names, array &$fragments, mixed $name, mixed $email, mixed $phone): void
    {
        if (is_string($name)) {
            $trimmed = trim($name);

            if ($trimmed !== '' && ! in_array($trimmed, $names, true)) {
                $names[] = $trimmed;
            }
        }

        foreach ([$email, $phone] as $fragment) {
            if (! is_string($fragment)) {
                continue;
            }

            $trimmed = trim($fragment);

            if (mb_strlen($trimmed) >= 3 && ! in_array($trimmed, $fragments, true)) {
                $fragments[] = $trimmed;
            }
        }
    }

    /**
     * @param  list<string>  $bookingIds
     * @param  list<string>  $names
     * @param  list<string>  $fragments
     */
    private function scrubIncidents(array $bookingIds, array $names, array $fragments): void
    {
        foreach (Incident::query()->whereIn('booking_id', $bookingIds)->orderBy('id')->get() as $incident) {
            $summary = $this->scrubText($incident->summary, $names, $fragments);

            if ($summary !== $incident->summary) {
                $incident->summary = $summary ?? $incident->summary;
                $incident->save();
            }
        }
    }

    /**
     * @param  list<string>  $bookingIds
     * @param  list<string>  $names
     * @param  list<string>  $fragments
     */
    private function scrubFeedback(array $bookingIds, array $names, array $fragments): void
    {
        if ($bookingIds === []) {
            return;
        }

        foreach (FeedbackRequest::query()->whereIn('booking_id', $bookingIds)->orderBy('id')->get() as $request) {
            $reason = $this->scrubText($request->skip_reason, $names, $fragments);
            $request->comment = null;
            $request->skip_reason = $reason;
            $request->save();
        }
    }

    /**
     * @param  list<string>  $bookingIds
     * @param  list<string>  $names
     * @param  list<string>  $fragments
     */
    /**
     * @param  list<string>  $bookingIds
     * @param  list<string>  $names
     * @param  list<string>  $fragments
     */
    private function scrubStaffNotes(array $bookingIds, array $names, array $fragments): void
    {
        foreach (Booking::query()->whereIn('id', $bookingIds)->orderBy('id')->get() as $booking) {
            $notes = $this->scrubText($booking->staff_notes, $names, $fragments);

            if ($notes !== $booking->staff_notes) {
                $booking->staff_notes = $notes;
                $booking->save();
            }
        }
    }

    /**
     * @param  list<string>  $bookingIds
     * @param  list<string>  $names
     * @param  list<string>  $fragments
     */
    private function scrubReturns(array $bookingIds, array $names, array $fragments): void
    {
        foreach (RentalReturn::query()->whereIn('booking_id', $bookingIds)->orderBy('id')->get() as $return) {
            $notes = $this->scrubText($return->notes, $names, $fragments);
            $reason = $this->scrubText($return->late_fee_reason, $names, $fragments);

            if ($notes !== $return->notes || $reason !== $return->late_fee_reason) {
                $return->notes = $notes;
                $return->late_fee_reason = $reason;
                $return->save();
            }
        }
    }

    /**
     * @param  list<string>  $bookingIds
     * @param  list<string>  $names
     * @param  list<string>  $fragments
     */
    private function scrubHooks(array $bookingIds, array $names, array $fragments): void
    {
        foreach (NotificationHook::query()->whereIn('booking_id', $bookingIds)->orderBy('id')->get() as $hook) {
            $payload = $this->scrubValue($hook->payload, $names, $fragments);
            $error = $this->scrubText($hook->delivery_error, $names, $fragments);
            $changed = $payload !== $hook->payload || $error !== $hook->delivery_error;

            if ($changed) {
                $hook->payload = is_array($payload) ? $payload : $hook->payload;
                $hook->delivery_error = $error;
                $hook->save();
            }
        }
    }

    /**
     * @param  list<string>  $bookingIds
     * @param  list<string>  $names
     * @param  list<string>  $fragments
     */
    private function scrubOutbox(array $bookingIds, array $names, array $fragments): void
    {
        foreach (IntegrationOutbox::query()->orderBy('id')->get() as $row) {
            $payload = $row->payload;
            $bookingId = $payload['booking_id'] ?? null;

            if (! is_string($bookingId) || ! in_array($bookingId, $bookingIds, true)) {
                continue;
            }

            $scrubbed = $this->scrubValue($payload, $names, $fragments);
            $error = $this->scrubText($row->last_error, $names, $fragments);

            if ($scrubbed !== $payload || $error !== $row->last_error) {
                $row->payload = is_array($scrubbed) ? $scrubbed : $payload;
                $row->last_error = $error;
                $row->save();
            }
        }
    }

    /**
     * @param  list<string>  $subjectIds
     * @param  list<string>  $fragments
     * @param  list<string>  $names
     */
    private function scrubAudits(array $subjectIds, array $fragments, array $names): void
    {
        $logs = AuditLog::query()->whereIn('subject_id', $subjectIds)->orderBy('id')->get();

        foreach ($fragments as $fragment) {
            $matches = AuditLog::query()
                ->where('properties', 'like', '%'.addcslashes($fragment, '%_\\').'%')
                ->orderBy('id')
                ->get();
            $logs = $logs->merge($matches);
        }

        foreach ($logs->unique('id') as $log) {
            $properties = $this->scrubValue($log->properties, $names, $fragments);

            if ($properties !== $log->properties) {
                $log->properties = $properties;
                $log->save();
            }
        }
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $fragments
     */
    private function scrubValue(mixed $value, array $names, array $fragments): mixed
    {
        if (is_string($value)) {
            return $this->scrubText($value, $names, $fragments);
        }

        if (! is_array($value)) {
            return $value;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            $clean[$key] = $this->scrubValue($item, $names, $fragments);
        }

        return $clean;
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $fragments
     */
    private function scrubText(?string $value, array $names, array $fragments): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        foreach ($names as $name) {
            if ($name === '' || $name === self::REMOVED_CUSTOMER || $name === self::REMOVED_PERSON) {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u';
            $replaced = preg_replace($pattern, self::REMOVED_PERSON, $value);

            if (is_string($replaced)) {
                $value = $replaced;
            }
        }

        foreach ($fragments as $fragment) {
            $value = str_replace($fragment, self::REMOVED_PERSON, $value);
        }

        return $value;
    }
}
