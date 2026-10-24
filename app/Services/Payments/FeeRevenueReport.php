<?php

namespace App\Services\Payments;

use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\Tenant;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;

/**
 * Sums stored platform-fee rows. It does not call Stripe and does not assign a channel.
 */
final class FeeRevenueReport
{
    /**
     * @return array{
     *     from: string|null,
     *     to: string|null,
     *     gross_cents: int,
     *     refunded_cents: int,
     *     manual_refunded_cents: int,
     *     net_cents: int,
     *     tenants: list<array{
     *         tenant_id: string,
     *         name: string,
     *         gross_cents: int,
     *         refunded_cents: int,
     *         manual_refunded_cents: int,
     *         net_cents: int,
     *         channels: list<array{
     *             channel: string,
     *             gross_cents: int,
     *             refunded_cents: int,
     *             manual_refunded_cents: int,
     *             net_cents: int,
     *             count: int
     *         }>
     *     }>
     * }
     */
    public function build(?string $from, ?string $to): array
    {
        $query = PlatformFee::withoutGlobalScope(TenantScope::class)
            ->select(['tenant_id', 'channel'])
            ->selectRaw('SUM(amount_cents) as gross_cents')
            ->selectRaw('SUM(refunded_cents) as refunded_cents')
            ->selectRaw('COUNT(*) as fee_count')
            ->groupBy('tenant_id', 'channel');

        if ($from !== null) {
            $query->where('created_at', '>=', $from.' 00:00:00');
        }

        if ($to !== null) {
            $end = CarbonImmutable::parse($to.' 00:00:00', 'UTC')->addDay()->format('Y-m-d H:i:s');
            $query->where('created_at', '<', $end);
        }

        $manual = $this->manualByTenantChannel($from, $to);

        /** @var array<string, array{gross_cents: int, refunded_cents: int, manual_refunded_cents: int, channels: list<array{channel: string, gross_cents: int, refunded_cents: int, manual_refunded_cents: int, net_cents: int, count: int}>}> $byTenant */
        $byTenant = [];

        foreach ($query->get() as $row) {
            $tenantId = $row->getAttribute('tenant_id');

            if (! is_string($tenantId) || $tenantId === '') {
                continue;
            }

            $gross = $this->integer($row, 'gross_cents');
            $refunded = $this->integer($row, 'refunded_cents');
            $channel = $row->getAttribute('channel');

            if (! isset($byTenant[$tenantId])) {
                $byTenant[$tenantId] = [
                    'gross_cents' => 0,
                    'refunded_cents' => 0,
                    'manual_refunded_cents' => 0,
                    'channels' => [],
                ];
            }

            $channelName = is_string($channel) ? $channel : '';
            $manualCents = $manual[$tenantId."\n".$channelName] ?? 0;
            $byTenant[$tenantId]['gross_cents'] += $gross;
            $byTenant[$tenantId]['refunded_cents'] += $refunded;
            $byTenant[$tenantId]['manual_refunded_cents'] += $manualCents;
            $byTenant[$tenantId]['channels'][] = [
                'channel' => $channelName,
                'gross_cents' => $gross,
                'refunded_cents' => $refunded,
                'manual_refunded_cents' => $manualCents,
                'net_cents' => $gross - $refunded,
                'count' => $this->integer($row, 'fee_count'),
            ];
        }

        $names = Tenant::query()->whereIn('id', array_keys($byTenant))->pluck('name', 'id');
        $tenants = [];

        foreach ($byTenant as $tenantId => $bucket) {
            $name = $names->get($tenantId);
            $channels = $bucket['channels'];
            usort(
                $channels,
                fn (array $left, array $right): int => $left['channel'] <=> $right['channel'],
            );

            $tenants[] = [
                'tenant_id' => $tenantId,
                'name' => is_string($name) ? $name : '',
                'gross_cents' => $bucket['gross_cents'],
                'refunded_cents' => $bucket['refunded_cents'],
                'manual_refunded_cents' => $bucket['manual_refunded_cents'],
                'net_cents' => $bucket['gross_cents'] - $bucket['refunded_cents'],
                'channels' => $channels,
            ];
        }

        usort(
            $tenants,
            fn (array $left, array $right): int => [$left['name'], $left['tenant_id']] <=> [$right['name'], $right['tenant_id']],
        );

        $gross = 0;
        $refunded = 0;
        $manualRefunded = 0;

        foreach ($tenants as $tenant) {
            $gross += $tenant['gross_cents'];
            $refunded += $tenant['refunded_cents'];
            $manualRefunded += $tenant['manual_refunded_cents'];
        }

        return [
            'from' => $from,
            'to' => $to,
            'gross_cents' => $gross,
            'refunded_cents' => $refunded,
            'manual_refunded_cents' => $manualRefunded,
            'net_cents' => $gross - $refunded,
            'tenants' => $tenants,
        ];
    }

    /**
     * Manual fee refunds on fees created in the window, keyed by tenant and channel.
     * The amount is already inside `platform_fees.refunded_cents`.
     *
     * @return array<string, int>
     */
    private function manualByTenantChannel(?string $from, ?string $to): array
    {
        $query = PlatformFeeRefund::withoutGlobalScope(TenantScope::class)
            ->join('platform_fees', 'platform_fees.id', '=', 'platform_fee_refunds.platform_fee_id')
            ->select('platform_fee_refunds.tenant_id as tenant_id', 'platform_fees.channel as channel')
            ->selectRaw('SUM(platform_fee_refunds.amount_cents) as manual_refunded_cents')
            ->groupBy('platform_fee_refunds.tenant_id', 'platform_fees.channel');

        if ($from !== null) {
            $query->where('platform_fees.created_at', '>=', $from.' 00:00:00');
        }

        if ($to !== null) {
            $end = CarbonImmutable::parse($to.' 00:00:00', 'UTC')->addDay()->format('Y-m-d H:i:s');
            $query->where('platform_fees.created_at', '<', $end);
        }

        $totals = [];

        foreach ($query->get() as $row) {
            $tenantId = $row->getAttribute('tenant_id');
            $channel = $row->getAttribute('channel');

            if (! is_string($tenantId) || $tenantId === '') {
                continue;
            }

            $name = is_string($channel) ? $channel : '';
            $amount = $row->getAttribute('manual_refunded_cents');
            $totals[$tenantId."\n".$name] = is_numeric($amount) ? (int) $amount : 0;
        }

        return $totals;
    }

    private function integer(PlatformFee $row, string $key): int
    {
        $value = $row->getAttribute($key);

        return is_numeric($value) ? (int) $value : 0;
    }
}
