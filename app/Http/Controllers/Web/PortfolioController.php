<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\RendersStaffPage;
use App\Models\Tenant;
use App\Services\Reports\PortfolioReport;
use App\Services\Workspaces\WorkspaceDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Owner rollup. The business id in the query is accepted only when this login
 * owns that workspace. Every other id is not found.
 */
class PortfolioController extends Controller
{
    use RendersStaffPage;

    public function __construct(
        private readonly WorkspaceDirectory $directory,
        private readonly PortfolioReport $report,
    ) {}

    public function show(Request $request): View
    {
        $resolved = $this->resolve($request);

        return $this->staffPage('All businesses', 'staff.portfolio', [
            'owned' => $resolved['owned'],
            'selection' => $resolved['selection'],
            'preset' => $resolved['preset'],
            'from' => $resolved['from'],
            'to' => $resolved['to'],
            'report' => $this->report->build($resolved['tenants'], $resolved['from'], $resolved['to']),
            'exportUrl' => '/staff/portfolio.csv?'.http_build_query([
                'business' => $resolved['selection'],
                'preset' => $resolved['preset'],
                'from' => $resolved['from'],
                'to' => $resolved['to'],
            ]),
        ]);
    }

    public function export(Request $request): Response
    {
        $resolved = $this->resolve($request);
        $csv = $this->report->toCsv($resolved['tenants'], $resolved['from'], $resolved['to']);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="portfolio.csv"',
        ]);
    }

    /**
     * @return array{
     *     owned: Collection<int, Tenant>,
     *     tenants: Collection<int, Tenant>,
     *     selection: string,
     *     preset: string,
     *     from: string,
     *     to: string
     * }
     */
    private function resolve(Request $request): array
    {
        $user = $this->staffUser($request);
        $current = $this->staffTenant($request);
        abort_unless($this->directory->isOwner($user, $current->id), 403);

        $owned = $this->directory->owned($user);
        $selection = $this->selection($request, $owned);
        $window = $this->window($request);

        return [
            'owned' => $owned,
            'tenants' => $selection['tenants'],
            'selection' => $selection['selection'],
            'preset' => $window['preset'],
            'from' => $window['from'],
            'to' => $window['to'],
        ];
    }

    /**
     * @param  Collection<int, Tenant>  $owned
     * @return array{selection: string, tenants: Collection<int, Tenant>}
     */
    private function selection(Request $request, Collection $owned): array
    {
        $raw = $request->query('business', 'all');

        if (! is_string($raw) || $raw === '' || $raw === 'all') {
            return ['selection' => 'all', 'tenants' => $owned->values()];
        }

        $match = $owned->first(fn (Tenant $tenant): bool => $tenant->id === $raw);

        if (! $match instanceof Tenant) {
            abort(404);
        }

        return ['selection' => $match->id, 'tenants' => collect([$match])];
    }

    /**
     * @return array{preset: string, from: string, to: string}
     */
    private function window(Request $request): array
    {
        $preset = $request->query('preset', '30');

        if (! is_string($preset) || $preset === '') {
            $preset = '30';
        }

        $today = CarbonImmutable::now('UTC')->startOfDay();

        if (in_array($preset, ['7', '30', '90'], true)) {
            $days = (int) $preset;

            return [
                'preset' => $preset,
                'from' => $today->subDays($days - 1)->toDateString(),
                'to' => $today->toDateString(),
            ];
        }

        if ($preset !== 'custom') {
            abort(422);
        }

        $from = $request->query('from');
        $to = $request->query('to');

        if (! is_string($from) || ! is_string($to) || ! $this->isDate($from) || ! $this->isDate($to) || $from > $to) {
            abort(422);
        }

        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');

        if (! $start instanceof CarbonImmutable || ! $end instanceof CarbonImmutable) {
            abort(422);
        }

        if ((int) $start->diffInDays($end, true) > 365) {
            abort(422);
        }

        return ['preset' => 'custom', 'from' => $from, 'to' => $to];
    }

    private function isDate(string $value): bool
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $value;
    }
}
