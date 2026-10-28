<?php

namespace App\Livewire\Platform;

use App\Domain\Payments\StripeNotConfiguredException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Payments\FeeListTruncatedException;
use App\Services\Payments\FeeReconciliation;
use App\Services\Payments\FeeRevenueReport;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Stored booking-fee totals for a platform administrator, with an on-demand Stripe compare.
 * Spec Section 11, Section 7.8, and Section 14 item 13.
 * Opening it does not call Stripe and does not write a price, tax, fee, or refund.
 */
#[Layout('layouts.platform')]
#[Title('Booking fees')]
class FeeReport extends Component
{
    public string $from = '';

    public string $to = '';

    public string $appliedFrom = '';

    public string $appliedTo = '';

    public ?string $problem = null;

    /**
     * Stripe compare for the period on screen. Fee ids stay off this state.
     *
     * @var array{
     *     balanced: bool,
     *     stored_gross_cents: int,
     *     stored_refunded_cents: int,
     *     stored_net_cents: int,
     *     stripe_gross_cents: int,
     *     stripe_refunded_cents: int,
     *     stripe_net_cents: int,
     *     differences: list<array{
     *         kind: string,
     *         label: string,
     *         workspace: string,
     *         stored_amount_cents: ?int,
     *         stored_refunded_cents: ?int,
     *         stripe_amount_cents: ?int,
     *         stripe_refunded_cents: ?int
     *     }>
     * }|null
     */
    public ?array $comparison = null;

    public function mount(AuditLogger $audit): void
    {
        $this->guard();

        $today = CarbonImmutable::now('UTC');
        $this->from = $today->subDays(29)->format('Y-m-d');
        $this->to = $today->format('Y-m-d');
        $this->appliedFrom = $this->from;
        $this->appliedTo = $this->to;
        $this->recordRevenueView($audit);
    }

    public function apply(AuditLogger $audit): void
    {
        $this->guard();

        $error = $this->windowError($this->from, $this->to);

        if ($error !== null) {
            $this->problem = $error;

            return;
        }

        $this->problem = null;
        $this->comparison = null;
        $this->appliedFrom = $this->from;
        $this->appliedTo = $this->to;
        $this->recordRevenueView($audit);
    }

    public function compare(FeeReconciliation $reconciliation, AuditLogger $audit): void
    {
        $this->guard();

        $error = $this->windowError($this->appliedFrom, $this->appliedTo);

        if ($error !== null) {
            $this->problem = $error;

            return;
        }

        try {
            $report = $reconciliation->build($this->appliedFrom, $this->appliedTo);
        } catch (StripeNotConfiguredException|FeeListTruncatedException $exception) {
            $this->comparison = null;
            $this->problem = $exception->getMessage();

            return;
        }

        $this->problem = null;
        $this->comparison = $this->present($report);

        $user = auth()->user();

        $audit->log(
            action: 'platform.fee_reconciliation_viewed',
            properties: [
                'from' => $this->appliedFrom,
                'to' => $this->appliedTo,
                'balanced' => $report['balanced'],
                'difference_count' => count($report['differences']),
            ],
            actor: $user instanceof User ? $user : null,
            request: request(),
            useContextTenant: false,
        );
    }

    public function render(FeeRevenueReport $report): View
    {
        $this->guard();

        $revenue = null;

        if ($this->windowError($this->appliedFrom, $this->appliedTo) === null) {
            $revenue = $report->build($this->appliedFrom, $this->appliedTo);
        }

        return view('livewire.platform.fee-report', [
            'revenue' => $revenue,
        ]);
    }

    private function guard(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403, 'Platform administrator access is required.');
    }

    private function recordRevenueView(AuditLogger $audit): void
    {
        $user = auth()->user();

        $audit->log(
            action: 'platform.fee_revenue_viewed',
            properties: ['from' => $this->appliedFrom, 'to' => $this->appliedTo],
            actor: $user instanceof User ? $user : null,
            request: request(),
            useContextTenant: false,
        );
    }

    private function windowError(string $from, string $to): ?string
    {
        if ($this->day($from) === null || $this->day($to) === null) {
            return 'Use a start date and an end date.';
        }

        if ($from > $to) {
            return 'The end date must be on or after the start date.';
        }

        return null;
    }

    private function day(string $value): ?CarbonImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $parsed;
    }

    /**
     * @param  array{
     *     from: string,
     *     to: string,
     *     stored_gross_cents: int,
     *     stored_refunded_cents: int,
     *     stored_net_cents: int,
     *     stripe_gross_cents: int,
     *     stripe_refunded_cents: int,
     *     stripe_net_cents: int,
     *     balanced: bool,
     *     differences: list<array{
     *         kind: string,
     *         stripe_fee_id: ?string,
     *         platform_fee_id: ?string,
     *         tenant_id: ?string,
     *         stored_amount_cents: ?int,
     *         stored_refunded_cents: ?int,
     *         stripe_amount_cents: ?int,
     *         stripe_refunded_cents: ?int
     *     }>
     * }  $report
     * @return array{
     *     balanced: bool,
     *     stored_gross_cents: int,
     *     stored_refunded_cents: int,
     *     stored_net_cents: int,
     *     stripe_gross_cents: int,
     *     stripe_refunded_cents: int,
     *     stripe_net_cents: int,
     *     differences: list<array{
     *         kind: string,
     *         label: string,
     *         workspace: string,
     *         stored_amount_cents: ?int,
     *         stored_refunded_cents: ?int,
     *         stripe_amount_cents: ?int,
     *         stripe_refunded_cents: ?int
     *     }>
     * }
     */
    private function present(array $report): array
    {
        $tenantIds = [];

        foreach ($report['differences'] as $difference) {
            if (is_string($difference['tenant_id']) && $difference['tenant_id'] !== '') {
                $tenantIds[] = $difference['tenant_id'];
            }
        }

        $names = Tenant::query()->whereIn('id', array_values(array_unique($tenantIds)))->pluck('name', 'id');
        $rows = [];

        foreach ($report['differences'] as $difference) {
            $tenantId = $difference['tenant_id'];
            $name = is_string($tenantId) ? $names->get($tenantId) : null;
            $rows[] = [
                'kind' => $difference['kind'],
                'label' => $this->kindLabel($difference['kind']),
                'workspace' => is_string($name) ? $name : '',
                'stored_amount_cents' => $difference['stored_amount_cents'],
                'stored_refunded_cents' => $difference['stored_refunded_cents'],
                'stripe_amount_cents' => $difference['stripe_amount_cents'],
                'stripe_refunded_cents' => $difference['stripe_refunded_cents'],
            ];
        }

        return [
            'balanced' => $report['balanced'],
            'stored_gross_cents' => $report['stored_gross_cents'],
            'stored_refunded_cents' => $report['stored_refunded_cents'],
            'stored_net_cents' => $report['stored_net_cents'],
            'stripe_gross_cents' => $report['stripe_gross_cents'],
            'stripe_refunded_cents' => $report['stripe_refunded_cents'],
            'stripe_net_cents' => $report['stripe_net_cents'],
            'differences' => $rows,
        ];
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'missing_on_stripe' => 'Stored here, missing on Stripe',
            'amount_mismatch' => 'Amounts differ',
            'missing_locally' => 'On Stripe, missing here',
            default => 'Difference',
        };
    }
}
