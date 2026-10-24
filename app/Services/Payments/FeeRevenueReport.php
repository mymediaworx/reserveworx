<?php

namespace App\Services\Payments;

use App\Models\PlatformFee;
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
     *     net_cents: int,
     *     tenants: list<array{
     *         tenant_id: string,
     *         name: string,
     *         gross_cents: int,
     *         refunded_cents: int,
     *         net_cents: int,
     *         channels: list<array{
     *             channel: string,
     *             gross_cents: int,
     *             refunded_cents: int,
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

        /** @var array<string, array{gross_cents: int, refunded_cents: int, channels: list<array{channel: string, gross_cents: int, refunded_cents: int, net_cents: int, count: int}>}> $byTenant */
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
                    'channels' => [],
                ];
            }

            $byTenant[$tenantId]['gross_cents'] += $gross;
            $byTenant[$tenantId]['refunded_cents'] += $refunded;
            $byTenant[$tenantId]['channels'][] = [
                'channel' => is_string($channel) ? $channel : '',
                'gross_cents' => $gross,
                'refunded_cents' => $refunded,
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

        foreach ($tenants as $tenant) {
            $gross += $tenant['gross_cents'];
            $refunded += $tenant['refunded_cents'];
        }

        return [
            'from' => $from,
            'to' => $to,
            'gross_cents' => $gross,
            'refunded_cents' => $refunded,
            'net_cents' => $gross - $refunded,
            'tenants' => $tenants,
        ];
    }

    private function integer(PlatformFee $row, string $key): int
    {
        $value = $row->getAttribute($key);

        return is_numeric($value) ? (int) $value : 0;
    }
}
