<?php

namespace App\Services\Booking;

use App\Domain\Pricing\CancellationRefund;
use App\Enums\CancellationPolicyKind;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\Payment;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The tenant's cancellation windows and no-show percent. With no rows saved,
 * a customer cancellation does not refund a card.
 */
final class CancellationPolicies
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return list<array{lead_minutes: int, refund_percent: int}>
     */
    public function customerTiers(): array
    {
        $tenantId = app(TenantManager::class)->id();

        if (! is_string($tenantId)) {
            return [];
        }

        return $this->customerTiersFor($tenantId);
    }

    /**
     * @return list<array{lead_minutes: int, refund_percent: int}>
     */
    public function customerTiersFor(string $tenantId): array
    {
        return CancellationPolicy::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('kind', CancellationPolicyKind::CustomerCancel)
            ->orderByDesc('lead_minutes')
            ->orderBy('id')
            ->get()
            ->map(fn (CancellationPolicy $row): array => [
                'lead_minutes' => $row->lead_minutes,
                'refund_percent' => $row->refund_percent,
            ])
            ->all();
    }

    public function noShowPercent(): ?int
    {
        $tenantId = app(TenantManager::class)->id();

        if (! is_string($tenantId)) {
            return null;
        }

        return $this->noShowPercentFor($tenantId);
    }

    public function noShowPercentFor(string $tenantId): ?int
    {
        $row = CancellationPolicy::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('kind', CancellationPolicyKind::NoShow)
            ->first();

        return $row instanceof CancellationPolicy ? $row->refund_percent : null;
    }

    /**
     * Replaces the current workspace's windows. An empty list and a null
     * no-show percent clear the policy.
     *
     * @param  list<array{lead_minutes: int, refund_percent: int}>  $tiers
     */
    public function replace(array $tiers, ?int $noShowPercent, ?User $actor): void
    {
        $normalized = $this->checked($tiers, $noShowPercent);
        $tenantId = app(TenantManager::class)->id();
        abort_unless(is_string($tenantId), 404);

        DB::transaction(function () use ($normalized, $noShowPercent, $tenantId): void {
            CancellationPolicy::query()->where('tenant_id', $tenantId)->delete();

            foreach ($normalized as $tier) {
                CancellationPolicy::query()->create([
                    'tenant_id' => $tenantId,
                    'kind' => CancellationPolicyKind::CustomerCancel,
                    'lead_minutes' => $tier['lead_minutes'],
                    'refund_percent' => $tier['refund_percent'],
                ]);
            }

            if ($noShowPercent !== null) {
                CancellationPolicy::query()->create([
                    'tenant_id' => $tenantId,
                    'kind' => CancellationPolicyKind::NoShow,
                    'lead_minutes' => 0,
                    'refund_percent' => $noShowPercent,
                ]);
            }
        });

        $setting = TenantSetting::query()->where('tenant_id', $tenantId)->first();
        $this->audit->log('cancellation_policy.updated', $setting, [
            'customer_cancel' => $normalized,
            'no_show_refund_percent' => $noShowPercent,
        ], actor: $actor);
    }

    /**
     * Plain sentences for the review page and the manage page.
     *
     * @return list<string>
     */
    public function sentencesFor(string $tenantId): array
    {
        $lines = [];

        foreach ($this->customerTiersFor($tenantId) as $tier) {
            $lines[] = 'A cancellation '.$this->leadPhrase($tier['lead_minutes']).' receives '.$this->outcomePhrase($tier['refund_percent']).'.';
        }

        $noShow = $this->noShowPercentFor($tenantId);

        if ($noShow !== null) {
            $lines[] = 'A no-show receives '.$this->outcomePhrase($noShow).'.';
        }

        return $lines;
    }

    public function cancelNote(Booking $booking, CarbonImmutable $at): string
    {
        $unchanged = 'Cancelling releases the time. It does not refund a card payment.';

        if (! $this->hasCardPayment($booking)) {
            return $unchanged;
        }

        $minutes = CancellationRefund::minutesUntil(
            CarbonImmutable::instance($booking->starts_at)->utc(),
            $at->utc(),
        );
        $percent = CancellationRefund::percent($this->customerTiersFor($booking->tenant_id), $minutes);

        if ($percent === 100) {
            return 'Cancelling refunds the card payment, including the booking fee.';
        }

        if ($percent >= 1) {
            return 'Cancelling refunds '.$percent.'% of the price, and the booking fee in the same proportion.';
        }

        return $unchanged;
    }

    public function hasCardPayment(Booking $booking): bool
    {
        return Payment::withoutGlobalScope(TenantScope::class)
            ->where('booking_id', $booking->id)
            ->where('tenant_id', $booking->tenant_id)
            ->where('method', 'stripe')
            ->where('status', '!=', 'pending')
            ->where('amount_cents', '>', 0)
            ->exists();
    }

    /**
     * @param  list<array{lead_minutes: int, refund_percent: int}>  $tiers
     * @return list<array{lead_minutes: int, refund_percent: int}>
     */
    private function checked(array $tiers, ?int $noShowPercent): array
    {
        if (count($tiers) > 8) {
            throw ValidationException::withMessages([
                'customer_cancel' => 'Save at most 8 cancellation windows.',
            ]);
        }

        if ($noShowPercent !== null && ($noShowPercent < 0 || $noShowPercent > 100)) {
            throw ValidationException::withMessages([
                'no_show_refund_percent' => 'The no-show refund percent must be from 0 to 100.',
            ]);
        }

        $seen = [];
        $normalized = [];

        foreach ($tiers as $tier) {
            $lead = $tier['lead_minutes'];
            $percent = $tier['refund_percent'];

            if ($lead < 0 || $lead > 525600 || $percent < 0 || $percent > 100) {
                throw ValidationException::withMessages([
                    'customer_cancel' => 'Each window needs a lead time from 0 to 525600 minutes and a refund percent from 0 to 100.',
                ]);
            }

            if (isset($seen[$lead])) {
                throw ValidationException::withMessages([
                    'customer_cancel' => 'Each cancellation window needs its own lead time.',
                ]);
            }

            $seen[$lead] = true;
            $normalized[] = [
                'lead_minutes' => $lead,
                'refund_percent' => $percent,
            ];
        }

        usort($normalized, fn (array $left, array $right): int => $right['lead_minutes'] <=> $left['lead_minutes']);

        return $normalized;
    }

    private function leadPhrase(int $minutes): string
    {
        if ($minutes === 0) {
            return 'any time before the start';
        }

        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);
            $unit = $hours === 1 ? 'hour' : 'hours';

            return 'at least '.$hours.' '.$unit.' before the start';
        }

        $unit = $minutes === 1 ? 'minute' : 'minutes';

        return 'at least '.$minutes.' '.$unit.' before the start';
    }

    private function outcomePhrase(int $percent): string
    {
        if ($percent === 100) {
            return 'a full refund, including the booking fee';
        }

        if ($percent === 0) {
            return 'no refund';
        }

        return 'a '.$percent.'% refund of the price, and the booking fee is refunded in the same proportion';
    }
}
