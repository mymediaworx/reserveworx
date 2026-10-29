<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Tenant;
use App\Services\Platform\RoleCatalogCheck;
use App\Support\Hostname;
use App\Support\SchedulerHeartbeat;
use App\Tenancy\TenantScope;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Smoke extends Command
{
    public const SCHEDULE_LAST_RUN_KEY = 'reserveworx:schedule:last-run';

    private const DEMO_TENANT = 'harbor-demo';

    protected $signature = 'reserveworx:smoke';

    protected $description = 'Check the activated release in-process. This command does not call the public internet and does not print secrets.';

    public function handle(): int
    {
        $failed = ! $this->checkDatabase();
        $failed = ! $this->checkMigrations() || $failed;
        $failed = ! $this->checkRoles() || $failed;
        $failed = ! $this->checkMail() || $failed;
        $failed = ! $this->checkQueue() || $failed;
        $host = $this->checkDemoTenant();
        $failed = $host === null || $failed;
        $failed = ! $this->checkPages($host) || $failed;
        $this->printScheduler();
        $this->fact('smoke', $failed ? 'failed' : 'ok');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkDatabase(): bool
    {
        try {
            $row = DB::selectOne('select 1 as ok');
            $ready = is_object($row) && (int) ($row->ok ?? 0) === 1;
        } catch (Throwable) {
            $ready = false;
        }

        $this->fact('db', $ready ? 'ok' : 'unreachable');

        return $ready;
    }

    private function checkMigrations(): bool
    {
        try {
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                $this->fact('migrations', 'missing');
                $this->fact('migrations_pending', 'unchecked');

                return false;
            }

            $ran = $migrator->getRepository()->getRan();
            $pending = 0;
            foreach ($migrator->getMigrationFiles([database_path('migrations')]) as $file) {
                if (! in_array($migrator->getMigrationName($file), $ran, true)) {
                    $pending++;
                }
            }
        } catch (Throwable) {
            $this->fact('migrations', 'unchecked');
            $this->fact('migrations_pending', 'unchecked');

            return false;
        }

        $this->fact('migrations', $pending === 0 ? 'current' : 'pending');
        $this->fact('migrations_pending', (string) $pending);

        return $pending === 0;
    }

    private function checkRoles(): bool
    {
        $status = app(RoleCatalogCheck::class)->status();
        $this->fact('roles', $status);

        return $status === 'synced';
    }

    private function checkMail(): bool
    {
        $mailer = config('mail.default');
        $mailer = is_string($mailer) && preg_match('/^[a-z0-9_-]{1,32}$/', $mailer) === 1 ? $mailer : 'unknown';
        $configured = $this->mailIsConfigured();

        $this->fact('mail_configured', $configured ? 'yes' : 'no');
        $this->fact('mail_mailer', $mailer);

        return $configured;
    }

    private function mailIsConfigured(): bool
    {
        if (config('mail.default') !== 'smtp') {
            return false;
        }

        $host = config('mail.mailers.smtp.host');
        $username = config('mail.mailers.smtp.username');
        $password = config('mail.mailers.smtp.password');
        $from = config('mail.from.address');
        $scheme = config('mail.mailers.smtp.scheme');
        $port = config('mail.mailers.smtp.port');

        if (! is_string($host) || $host === '' || in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true)) {
            return false;
        }

        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            return false;
        }

        if (! is_string($from) || $from === '' || str_ends_with(strtolower($from), '@example.com')) {
            return false;
        }

        if (! is_string($scheme) || $scheme === '') {
            return false;
        }

        if (is_int($port)) {
            $portNumber = $port;
        } elseif (is_string($port) && preg_match('/^\d+$/', $port) === 1) {
            $portNumber = (int) $port;
        } else {
            return false;
        }

        return $portNumber >= 1 && $portNumber <= 65535;
    }

    private function checkQueue(): bool
    {
        $connection = config('queue.default');
        $connection = is_string($connection) && preg_match('/^[a-z0-9_-]{1,32}$/', $connection) === 1 ? $connection : 'unknown';
        $table = config('queue.connections.database.table', 'jobs');

        try {
            $present = is_string($table)
                && preg_match('/^[A-Za-z0-9_]+$/', $table) === 1
                && Schema::hasTable($table);
        } catch (Throwable) {
            $this->fact('queue_connection', $connection);
            $this->fact('queue_table', 'unchecked');

            return false;
        }

        $this->fact('queue_connection', $connection);
        $this->fact('queue_table', $present ? 'present' : 'missing');

        return $connection === 'database' && $present;
    }

    private function checkDemoTenant(): ?string
    {
        $this->fact('demo_tenant', self::DEMO_TENANT);

        try {
            $host = $this->demoHost();
        } catch (Throwable) {
            $host = null;
        }

        $this->fact('demo_tenant_resolved', $host === null ? 'no' : 'yes');
        $this->fact('demo_host', $host ?? 'none');

        return $host;
    }

    private function demoHost(): ?string
    {
        $tenant = Tenant::query()->where('slug', self::DEMO_TENANT)->first();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $platform = config('reserveworx.platform_domains', []);
        $domain = $tenant->domains()
            ->whereNotNull('verified_at')
            ->orderByDesc('is_primary')
            ->orderBy('hostname')
            ->get()
            ->first(function (Domain $domain) use ($platform): bool {
                $host = Hostname::normalize($domain->hostname);

                if (! Hostname::isValid($host)) {
                    return false;
                }

                return ! is_array($platform) || ! in_array($host, $platform, true);
            });

        if (! $domain instanceof Domain) {
            return null;
        }

        $host = Hostname::normalize($domain->hostname);
        $matched = Domain::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('hostname', $host)
            ->whereNotNull('verified_at')
            ->first();

        if (! $matched instanceof Domain || $matched->tenant_id !== $tenant->id) {
            return null;
        }

        return $host;
    }

    private function checkPages(?string $host): bool
    {
        $booking = $this->page($host, '/', $this->demoMarker());
        $staff = $this->page($host, '/staff/login', 'Team sign in');
        $signup = $this->page($this->platformHost(), '/signup', 'Start a workspace');

        $this->fact('booking_http', $booking['http']);
        $this->fact('booking_page', $booking['page']);
        $this->fact('staff_login_http', $staff['http']);
        $this->fact('staff_login_page', $staff['page']);
        $this->fact('signup_http', $signup['http']);
        $this->fact('signup_page', $signup['page']);

        return $booking['page'] === 'ok' && $staff['page'] === 'ok' && $signup['page'] === 'ok';
    }

    /**
     * @return array{http: string, page: string}
     */
    private function page(?string $host, string $path, ?string $marker): array
    {
        if ($host === null || $host === '' || $marker === null || $marker === '') {
            return ['http' => 'skipped', 'page' => 'skipped'];
        }

        try {
            $kernel = app(Kernel::class);
            $request = Request::create('http://'.$host.$path, 'GET');
            $response = $kernel->handle($request);
        } catch (Throwable) {
            return ['http' => 'error', 'page' => 'failed'];
        }

        $status = $response->getStatusCode();
        $body = $response->getContent();
        $rendered = is_string($body) && $this->bodyHas($body, $marker);

        return [
            'http' => (string) $status,
            'page' => $status === Response::HTTP_OK && $rendered ? 'ok' : 'failed',
        ];
    }

    private function demoMarker(): ?string
    {
        try {
            $tenant = Tenant::query()->where('slug', self::DEMO_TENANT)->first();
        } catch (Throwable) {
            return null;
        }

        if (! $tenant instanceof Tenant || $tenant->name === '') {
            return null;
        }

        return $tenant->name.' bookings';
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

    private function bodyHas(string $body, string $needle): bool
    {
        return str_contains($body, $needle)
            || str_contains($body, htmlspecialchars($needle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    private function printScheduler(): void
    {
        try {
            $recorded = $this->recordedTimestamp(Cache::get(self::SCHEDULE_LAST_RUN_KEY));
        } catch (Throwable) {
            $recorded = null;
        }

        $this->fact('scheduler_last_run', $recorded ?? 'not_recorded');
        $status = app(SchedulerHeartbeat::class)->status();
        $this->fact('scheduler', match ($status) {
            'ok' => 'ok',
            'stale' => 'stale. No schedule:run in '.SchedulerHeartbeat::STALE_AFTER_MINUTES.' minutes. Check the Site Tools cron',
            default => 'not_seen. Add the Site Tools cron for schedule:run',
        });
    }

    private function recordedTimestamp(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc()->format('Y-m-d\TH:i:s\Z');
        }

        if (is_int($value) || (is_string($value) && preg_match('/^\d{10}$/', $value) === 1)) {
            $unix = (int) $value;
            if ($unix < 1_500_000_000 || $unix > 4_000_000_000) {
                return null;
            }

            return Carbon::createFromTimestampUTC($unix)->format('Y-m-d\TH:i:s\Z');
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return null;
        }
    }

    private function fact(string $key, string $value): void
    {
        $this->line($key.'='.str_replace(["\r", "\n"], '', $value));
    }
}
