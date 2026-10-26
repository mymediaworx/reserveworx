<?php

namespace App\Services\Staff;

use App\Enums\StaffUndoKind;
use App\Enums\WaitlistStatus;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\LocationClosure;
use App\Models\Pool;
use App\Models\PromoCode;
use App\Models\StaffUndoAction;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\AuditLogger;
use App\Services\Booking\BookingCancellation;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Ten-second undo for staff destructive actions. Deletes are soft deletes.
 * A booking cancellation with no refund yet is only recorded here until the
 * window passes, so the customer is not notified and a refund does not start.
 */
final class StaffUndo
{
    public const WINDOW_SECONDS = 10;

    public function __construct(
        private readonly TenantManager $tenants,
        private readonly StaffLocationAccess $locations,
        private readonly BookingCancellation $cancellation,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function offer(
        User $actor,
        StaffUndoKind $kind,
        string $message,
        ?string $locationId,
        ?Model $subject,
        array $payload,
    ): StaffUndoAction {
        $tenantId = $this->tenants->id();
        abort_unless(is_string($tenantId) && $tenantId !== '', 404);

        return StaffUndoAction::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $actor->id,
            'location_id' => $locationId,
            'kind' => $kind,
            'subject_type' => $subject === null ? null : $subject::class,
            'subject_id' => $subject?->getKey(),
            'payload' => $payload,
            'message' => $message,
            'expires_at' => now()->addSeconds(self::WINDOW_SECONDS),
        ]);
    }

    public function coversBooking(string $bookingId): bool
    {
        return in_array($bookingId, $this->openBookingIds(), true);
    }

    /**
     * @return list<string>
     */
    public function openBookingIds(): array
    {
        $ids = [];

        $actions = StaffUndoAction::query()
            ->where('kind', StaffUndoKind::BookingCancel)
            ->whereNull('undone_at')
            ->whereNull('committed_at')
            ->where('expires_at', '>', now())
            ->get();

        foreach ($actions as $action) {
            foreach ($this->bookingSpecs($action) as $spec) {
                $ids[] = $spec['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    public function restore(User $actor, string $id): void
    {
        DB::transaction(function () use ($actor, $id): void {
            $action = StaffUndoAction::query()->lockForUpdate()->find($id);
            abort_unless($action instanceof StaffUndoAction, 404);
            abort_unless($action->user_id === $actor->id, 404);
            abort_unless($action->undone_at === null && $action->committed_at === null, 409, 'That change can no longer be undone.');
            abort_unless($action->expires_at->greaterThan(now()), 409, 'That change can no longer be undone.');

            $this->applyRestore($actor, $action);
            $action->undone_at = now();
            $action->save();
        });
    }

    public function commitIfDue(string $id): bool
    {
        $action = StaffUndoAction::query()->find($id);

        if (! $action instanceof StaffUndoAction || $action->isOpen() || $action->undone_at !== null || $action->committed_at !== null) {
            return false;
        }

        return $this->commitOne($action->id);
    }

    /**
     * @param  (callable(): bool)|null  $shouldStop
     */
    public function commitDue(?callable $shouldStop = null): int
    {
        $ids = StaffUndoAction::withoutGlobalScope(TenantScope::class)
            ->whereNull('undone_at')
            ->whereNull('committed_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->pluck('id');

        $count = 0;

        foreach ($ids as $id) {
            if ($shouldStop !== null && $shouldStop()) {
                break;
            }

            if (is_string($id) && $this->commitOne($id)) {
                $count++;
            }
        }

        return $count;
    }

    private function commitOne(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $action = StaffUndoAction::withoutGlobalScope(TenantScope::class)->lockForUpdate()->find($id);

            if (! $action instanceof StaffUndoAction || $action->undone_at !== null || $action->committed_at !== null) {
                return false;
            }

            if ($action->expires_at->greaterThan(now())) {
                return false;
            }

            $tenant = Tenant::query()->find($action->tenant_id);
            $actor = User::query()->find($action->user_id);

            if ($tenant instanceof Tenant && $actor instanceof User) {
                $previous = $this->tenants->get();
                $this->tenants->set($tenant);

                try {
                    $this->locations->suspend(function () use ($action, $actor): void {
                        $this->applyCommit($action, $actor);
                    });
                    $action->committed_at = now();
                    $action->save();
                } finally {
                    if ($previous instanceof Tenant) {
                        $this->tenants->set($previous);
                    } else {
                        $this->tenants->forget();
                    }
                }

                return true;
            }

            StaffUndoAction::withoutGlobalScope(TenantScope::class)
                ->whereKey($action->id)
                ->whereNull('undone_at')
                ->whereNull('committed_at')
                ->update(['committed_at' => now()]);

            return true;
        });
    }

    private function applyCommit(StaffUndoAction $action, User $actor): void
    {
        match ($action->kind) {
            StaffUndoKind::BookingCancel => $this->commitBookings($action, $actor),
            StaffUndoKind::PromoDelete => $this->retirePromoCode($action),
            StaffUndoKind::AssetDelete,
            StaffUndoKind::PoolDelete,
            StaffUndoKind::ClosureDelete,
            StaffUndoKind::WaitlistRemove => null,
        };
    }

    private function commitBookings(StaffUndoAction $action, User $actor): void
    {
        $problems = [];

        foreach ($this->bookingSpecs($action) as $spec) {
            $booking = Booking::query()->with(['customer', 'service'])->find($spec['id']);

            if (! $booking instanceof Booking) {
                continue;
            }

            $result = $this->cancellation->finalize($booking, $actor, $spec['refund_card']);

            if ($result['problem'] !== null) {
                $problems[] = $result['problem'];
                $this->audit->log('booking.cancel_failed', $booking, ['problem' => $result['problem']], actor: $actor, request: request());
            }
        }

        if ($problems !== []) {
            $payload = $action->payload;
            $payload['problems'] = $problems;
            $action->payload = $payload;
        }
    }

    private function retirePromoCode(StaffUndoAction $action): void
    {
        $promo = PromoCode::withTrashed()->find($action->subject_id);

        if (! $promo instanceof PromoCode || ! $promo->trashed()) {
            return;
        }

        $promo->code = $this->retiredPromoCode($promo);
        $promo->save();
    }

    private function applyRestore(User $actor, StaffUndoAction $action): void
    {
        match ($action->kind) {
            StaffUndoKind::BookingCancel => $this->restoreBooking($actor, $action),
            StaffUndoKind::AssetDelete => $this->restoreSoft($actor, $action, 'asset.restored'),
            StaffUndoKind::PoolDelete => $this->restoreSoft($actor, $action, 'pool.restored'),
            StaffUndoKind::ClosureDelete => $this->restoreSoft($actor, $action, 'closure.restored'),
            StaffUndoKind::PromoDelete => $this->restoreSoft($actor, $action, 'promo_code.restored'),
            StaffUndoKind::WaitlistRemove => $this->restoreWaitlist($actor, $action),
        };
    }

    private function restoreBooking(User $actor, StaffUndoAction $action): void
    {
        $ids = [];

        foreach ($this->bookingSpecs($action) as $spec) {
            $booking = Booking::query()->find($spec['id']);
            abort_unless($booking instanceof Booking, 404);
            Gate::forUser($actor)->authorize('update', $booking);
            $ids[] = $booking->id;
        }

        $this->audit->log('booking.cancel_undone', null, ['booking_ids' => $ids], actor: $actor, request: request());
    }

    private function restoreSoft(User $actor, StaffUndoAction $action, string $auditAction): void
    {
        $model = match ($action->kind) {
            StaffUndoKind::AssetDelete => Asset::withTrashed()->whereKey($action->subject_id)->first(),
            StaffUndoKind::PoolDelete => Pool::withTrashed()->whereKey($action->subject_id)->first(),
            StaffUndoKind::ClosureDelete => LocationClosure::withTrashed()->whereKey($action->subject_id)->first(),
            StaffUndoKind::PromoDelete => PromoCode::withTrashed()->whereKey($action->subject_id)->first(),
            default => null,
        };
        abort_unless(
            $model instanceof Asset || $model instanceof Pool || $model instanceof LocationClosure || $model instanceof PromoCode,
            404,
        );
        Gate::forUser($actor)->authorize('delete', $model);
        $model->restore();
        $this->audit->log($auditAction, $model, [], actor: $actor, request: request());
    }

    private function restoreWaitlist(User $actor, StaffUndoAction $action): void
    {
        $entry = WaitlistEntry::query()->find($action->subject_id);
        abort_unless($entry instanceof WaitlistEntry, 404);
        Gate::forUser($actor)->authorize('update', $entry);
        abort_unless($entry->status === WaitlistStatus::Cancelled, 409, 'That change can no longer be undone.');

        $payload = $action->payload;
        $status = WaitlistStatus::tryFrom(is_string($payload['status'] ?? null) ? $payload['status'] : '');
        abort_unless($status instanceof WaitlistStatus, 409, 'That change can no longer be undone.');
        abort_unless($this->waitlistSnapshotMatches($entry, $payload), 409, 'That change can no longer be undone.');

        $entry->status = $status;
        $entry->save();
        $this->audit->log('waitlist.restored', $entry, [], actor: $actor, request: request());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function waitlistSnapshotMatches(WaitlistEntry $entry, array $payload): bool
    {
        return ($payload['customer_name'] ?? null) === $entry->customer_name
            && ($payload['customer_email'] ?? null) === $entry->customer_email
            && ($payload['customer_phone'] ?? null) === $entry->customer_phone
            && ($payload['participants'] ?? null) === $entry->participants;
    }

    /**
     * @return list<array{id: string, refund_card: bool}>
     */
    private function bookingSpecs(StaffUndoAction $action): array
    {
        $rows = $action->payload['bookings'] ?? null;

        if (! is_array($rows)) {
            return [];
        }

        $specs = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || $row['id'] === '') {
                continue;
            }

            $specs[] = [
                'id' => $row['id'],
                'refund_card' => ($row['refund_card'] ?? false) === true,
            ];
        }

        return $specs;
    }

    private function retiredPromoCode(PromoCode $promo): string
    {
        $suffix = '#'.$promo->id;
        $room = 40 - strlen($suffix);
        $prefix = $room > 0 ? substr($promo->code, 0, $room) : '';

        return $prefix.$suffix;
    }
}
