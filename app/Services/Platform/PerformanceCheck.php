<?php

namespace App\Services\Platform;

use App\Enums\TenantStatus;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\SignupController;
use App\Http\Controllers\WidgetController;
use App\Models\Domain;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\Widget\WidgetTokens;
use App\Support\Hostname;
use App\Support\MemoryLimit;
use App\Support\Subdomain;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Throwable;

/**
 * Renders the public product page, signup, and one booking page on this
 * server and counts the queries. The work is rolled back. Nothing is sent
 * over the public internet.
 */
final class PerformanceCheck
{
    public const PRODUCT_QUERY_BUDGET = 8;

    public const SIGNUP_QUERY_BUDGET = 8;

    public const BOOKING_QUERY_BASE = 16;

    public const BOOKING_QUERY_PER_SERVICE = 8;

    /**
     * @return array{within: bool, checks: list<array{key: string, label: string, state: string, detail: string, line: string}>, facts: list<array{key: string, value: string}>}
     */
    public function build(): array
    {
        $facts = [];
        $checks = [
            $this->memory($facts),
            $this->product($facts),
            $this->signup($facts),
            $this->booking($facts),
            $this->check(
                'public_internet',
                'Public internet',
                'note',
                'This process did not call the public internet. Run the command on the SiteGround server to measure that machine.',
            ),
        ];

        $within = collect($checks)->every(fn (array $check): bool => $check['state'] !== 'fail');

        return [
            'within' => $within,
            'checks' => $checks,
            'facts' => $facts,
        ];
    }

    /**
     * @param  list<array{key: string, value: string}>  $facts
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function memory(array &$facts): array
    {
        $value = trim(ini_get('memory_limit'));
        $bytes = MemoryLimit::bytes($value);

        if ($bytes === null) {
            return $this->check('memory_limit', 'Memory', 'fail', 'memory_limit is not a size this check can read.');
        }

        $facts[] = ['key' => 'memory_limit_value', 'value' => $value];

        if ($bytes === -1) {
            return $this->check('memory_limit', 'Memory', 'pass', 'memory_limit is unlimited, which is at least 128M.');
        }

        if ($bytes >= MemoryLimit::FLOOR_BYTES) {
            return $this->check('memory_limit', 'Memory', 'pass', 'memory_limit is '.$value.', which is at least 128M.');
        }

        return $this->check('memory_limit', 'Memory', 'fail', 'memory_limit is '.$value.', which is below 128M.');
    }

    /**
     * @param  list<array{key: string, value: string}>  $facts
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function product(array &$facts): array
    {
        $host = $this->platformHost();

        if ($host === null) {
            $this->errorFact($facts, 'product_error', 'missing_host');

            return $this->check('product', 'Product page', 'fail', 'The platform host is not configured, so the product page was not measured.');
        }

        $measured = $this->measure(function () use ($host): void {
            $request = Request::create('http://'.$host.'/', 'GET');
            $view = app(HomeController::class)(
                $request,
                app(TenantManager::class),
                app(WidgetTokens::class),
                app(WidgetController::class),
            );
            $this->render($view->render());
        });
        $this->timing($facts, 'product', $measured);

        if (! $measured['ok']) {
            return $this->check('product', 'Product page', 'fail', 'The product page did not render.');
        }

        if ($measured['queries'] > self::PRODUCT_QUERY_BUDGET) {
            return $this->check(
                'product',
                'Product page',
                'fail',
                'The product page used '.$measured['queries'].' queries. The budget is '.self::PRODUCT_QUERY_BUDGET.'.',
            );
        }

        return $this->check(
            'product',
            'Product page',
            'pass',
            'The product page rendered with '.$measured['queries'].' queries.',
        );
    }

    /**
     * @param  list<array{key: string, value: string}>  $facts
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function signup(array &$facts): array
    {
        $host = $this->platformHost();

        if ($host === null) {
            $this->errorFact($facts, 'signup_error', 'missing_host');

            return $this->check('signup', 'Signup', 'fail', 'The platform host is not configured, so signup was not measured.');
        }

        $measured = $this->measure(function () use ($host): void {
            $request = Request::create('http://'.$host.'/signup', 'GET');
            $view = app(SignupController::class)->create($request, app(Subdomain::class));
            $this->render($view->render());
        });
        $this->timing($facts, 'signup', $measured);

        if (! $measured['ok']) {
            return $this->check('signup', 'Signup', 'fail', 'Signup did not render.');
        }

        if ($measured['queries'] > self::SIGNUP_QUERY_BUDGET) {
            return $this->check(
                'signup',
                'Signup',
                'fail',
                'Signup used '.$measured['queries'].' queries. The budget is '.self::SIGNUP_QUERY_BUDGET.'.',
            );
        }

        return $this->check('signup', 'Signup', 'pass', 'Signup rendered with '.$measured['queries'].' queries.');
    }

    /**
     * @param  list<array{key: string, value: string}>  $facts
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function booking(array &$facts): array
    {
        $domain = $this->bookingDomain();

        if (! $domain instanceof Domain) {
            $facts[] = ['key' => 'booking_host', 'value' => 'none'];
            $this->errorFact($facts, 'booking_error', 'none');

            return $this->check(
                'booking',
                'Booking page',
                'note',
                'No verified workspace is loaded, so the booking page was not measured.',
            );
        }

        $host = Hostname::normalize($domain->hostname);
        $tenant = $domain->tenant;

        if (! $tenant instanceof Tenant) {
            $facts[] = ['key' => 'booking_host', 'value' => 'none'];
            $this->errorFact($facts, 'booking_error', 'none');

            return $this->check(
                'booking',
                'Booking page',
                'note',
                'No verified workspace is loaded, so the booking page was not measured.',
            );
        }

        $facts[] = ['key' => 'booking_host', 'value' => $host];
        $manager = app(TenantManager::class);
        $previous = $manager->get();
        $manager->set($tenant);

        try {
            $serviceCount = Service::query()->atListedLocation()->count();
        } finally {
            $previous instanceof Tenant ? $manager->set($previous) : $manager->forget();
        }

        $facts[] = ['key' => 'booking_services', 'value' => (string) $serviceCount];
        $budget = self::BOOKING_QUERY_BASE + (self::BOOKING_QUERY_PER_SERVICE * $serviceCount);
        $measured = $this->measure(function () use ($tenant, $host, $manager): void {
            $manager->set($tenant);

            try {
                $request = Request::create('http://'.$host.'/', 'GET');
                $request->attributes->set('tenant', $tenant);
                $view = app(HomeController::class)(
                    $request,
                    $manager,
                    app(WidgetTokens::class),
                    app(WidgetController::class),
                );
                $manager->set($tenant);
                $this->render($view->render());
            } finally {
                $manager->forget();
            }
        });
        $this->timing($facts, 'booking', $measured);

        if (! $measured['ok']) {
            return $this->check('booking', 'Booking page', 'fail', 'The booking page did not render.');
        }

        if ($measured['queries'] > $budget) {
            return $this->check(
                'booking',
                'Booking page',
                'fail',
                'The booking page for '.$host.' used '.$measured['queries'].' queries for '.$serviceCount.' services. The budget is '.$budget.'.',
            );
        }

        return $this->check(
            'booking',
            'Booking page',
            'pass',
            'The booking page for '.$host.' rendered with '.$measured['queries'].' queries for '.$serviceCount.' services.',
        );
    }

    /**
     * @param  Closure(): void  $render
     * @return array{ok: bool, queries: int, ms: int, error: string}
     */
    private function measure(Closure $render): array
    {
        $connection = DB::connection();
        $level = $connection->transactionLevel();
        $started = hrtime(true);
        $ok = false;
        $error = 'none';

        try {
            $connection->beginTransaction();
            $connection->flushQueryLog();
            $connection->enableQueryLog();
            $started = hrtime(true);
            $this->shareErrors();
            $render();
            $ok = true;
        } catch (Throwable $thrown) {
            $ok = false;
            $error = $this->shortName($thrown);
        } finally {
            $queries = count($connection->getQueryLog());
            $ms = (int) min(999_999, intdiv(hrtime(true) - $started, 1_000_000));
            $connection->disableQueryLog();

            while ($connection->transactionLevel() > $level) {
                $connection->rollBack();
            }
        }

        return [
            'ok' => $ok,
            'queries' => $queries,
            'ms' => $ms,
            'error' => $error,
        ];
    }

    /**
     * @param  list<array{key: string, value: string}>  $facts
     * @param  array{ok: bool, queries: int, ms: int, error: string}  $measured
     */
    private function timing(array &$facts, string $name, array $measured): void
    {
        $facts[] = ['key' => $name.'_queries', 'value' => (string) $measured['queries']];
        $facts[] = ['key' => $name.'_ms', 'value' => (string) $measured['ms']];
        $this->errorFact($facts, $name.'_error', $measured['error']);
    }

    /**
     * @param  list<array{key: string, value: string}>  $facts
     */
    private function errorFact(array &$facts, string $key, string $value): void
    {
        $facts[] = ['key' => $key, 'value' => $value];
    }

    private function shareErrors(): void
    {
        $view = app('view');

        if (! $view->shared('errors') instanceof ViewErrorBag) {
            $view->share('errors', new ViewErrorBag);
        }
    }

    private function render(string $html): void
    {
        if ($html === '') {
            throw new \RuntimeException('The page rendered nothing.');
        }

        $view = app('view');
        $view->flushSections();
        $view->flushStacks();
        $view->flushFragments();
    }

    private function bookingDomain(): ?Domain
    {
        $platform = config('reserveworx.platform_domains', []);
        $platform = is_array($platform) ? $platform : [];
        $domains = Domain::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereNotNull('verified_at')
            ->orderBy('hostname')
            ->get();

        foreach ($domains as $domain) {
            $host = Hostname::normalize($domain->hostname);

            if (! Hostname::isValid($host) || in_array($host, $platform, true)) {
                continue;
            }

            $tenant = $domain->tenant;

            if (! $tenant instanceof Tenant) {
                continue;
            }

            if (! in_array($tenant->status, [TenantStatus::Onboarding, TenantStatus::Active], true)) {
                continue;
            }

            return $domain;
        }

        return null;
    }

    private function platformHost(): ?string
    {
        $configured = config('reserveworx.platform_domains', []);

        if (! is_array($configured)) {
            return null;
        }

        $hosts = [];

        foreach ($configured as $host) {
            if (is_string($host) && $host !== '') {
                $hosts[] = Hostname::normalize($host);
            }
        }

        if (in_array('app.reserveworx.com', $hosts, true)) {
            return 'app.reserveworx.com';
        }

        foreach ($hosts as $host) {
            if (Hostname::isValid($host)) {
                return $host;
            }
        }

        return null;
    }

    private function shortName(Throwable $thrown): string
    {
        $name = $thrown::class;
        $position = strrpos($name, '\\');
        $short = $position === false ? $name : substr($name, $position + 1);

        return preg_match('/^[A-Za-z0-9_]+$/', $short) === 1 ? $short : 'error';
    }

    /**
     * @param  'pass'|'fail'|'note'  $state
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function check(string $key, string $label, string $state, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'detail' => $detail,
            'line' => $state,
        ];
    }
}
