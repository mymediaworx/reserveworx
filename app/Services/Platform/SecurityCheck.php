<?php

namespace App\Services\Platform;

use App\Enums\Industry;
use App\Enums\TenantStatus;
use App\Models\GhlConnection;
use App\Models\Tenant;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Local answers for Spec Section 12 credential storage, card data, CSRF,
 * and the session cookie. A sample token is written and rolled back.
 * SameSite must be lax or strict. A different value is not printed.
 * Nothing here calls Stripe or keeps a row.
 */
final class SecurityCheck
{
    public const PROBE_MARK = 'rw-secret-probe-';

    /**
     * @return array{secure: bool, checks: list<array{key: string, label: string, state: string, detail: string, line: string}>}
     */
    public function build(): array
    {
        $probe = $this->probe();
        $checks = [
            $this->stored('ghl_token', 'GoHighLevel token', $probe['ghl'], 'The GoHighLevel private token is encrypted at rest.', 'The GoHighLevel private token was not stored encrypted.', 'The GoHighLevel private token could not be checked.'),
            $this->stored('webhook_secret', 'Webhook secret', $probe['webhook'], 'The outbound webhook secret is encrypted at rest.', 'The outbound webhook secret was not stored encrypted.', 'The outbound webhook secret could not be checked.'),
            $this->cardColumns(),
            $this->csrf(),
            $this->httpOnly(),
            $this->sameSite(),
            $this->auditLog(),
        ];

        $secure = collect($checks)->every(fn (array $check): bool => $check['state'] !== 'fail');

        return [
            'secure' => $secure,
            'checks' => $checks,
        ];
    }

    /**
     * @return array{ghl: ?bool, webhook: ?bool}
     */
    private function probe(): array
    {
        $token = self::PROBE_MARK.'ghl-'.Str::lower(Str::random(16));
        $secret = self::PROBE_MARK.'wh-'.Str::lower(Str::random(16));
        $result = ['ghl' => null, 'webhook' => null];
        $connection = DB::connection();
        $level = $connection->transactionLevel();

        try {
            $connection->beginTransaction();
            $tenant = Tenant::query()->create([
                'name' => 'Security check',
                'slug' => 'security-check-'.Str::lower(Str::random(8)),
                'status' => TenantStatus::Onboarding,
                'industry' => Industry::BoatRentals,
            ]);
            $ghl = GhlConnection::query()->create([
                'tenant_id' => $tenant->id,
                'location_id' => 'loc_security_check',
                'private_integration_token' => $token,
            ]);
            $rawToken = DB::table('ghl_connections')->where('id', $ghl->id)->value('private_integration_token');
            $result['ghl'] = $ghl->private_integration_token === $token && $this->hidden($rawToken, $token);

            $endpoint = WebhookEndpoint::query()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Security check',
                'url' => 'https://security-check.invalid/hook',
                'secret' => $secret,
                'events' => ['booking.confirmed'],
                'active' => true,
            ]);
            $rawSecret = DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret');
            $result['webhook'] = $endpoint->secret === $secret && $this->hidden($rawSecret, $secret);
        } catch (Throwable) {
            $result = ['ghl' => null, 'webhook' => null];
        } finally {
            while (DB::connection()->transactionLevel() > $level) {
                DB::connection()->rollBack();
            }
        }

        return $result;
    }

    private function hidden(mixed $raw, string $plain): bool
    {
        return is_string($raw) && $raw !== '' && ! str_contains($raw, $plain);
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function stored(string $key, string $label, ?bool $encrypted, string $pass, string $fail, string $unchecked): array
    {
        if ($encrypted === null) {
            return $this->check($key, $label, 'fail', $unchecked);
        }

        return $this->check($key, $label, $encrypted ? 'pass' : 'fail', $encrypted ? $pass : $fail);
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function cardColumns(): array
    {
        $found = [];

        foreach (['payments', 'stripe_accounts'] as $table) {
            try {
                $columns = Schema::hasTable($table) ? Schema::getColumnListing($table) : null;
            } catch (Throwable) {
                $columns = null;
            }

            if ($columns === null) {
                return $this->check('card_columns', 'Card data', 'fail', 'The '.$table.' table could not be checked.');
            }

            foreach ($columns as $column) {
                if ($this->forbidden($column)) {
                    $found[] = $table.'.'.$column;
                }
            }
        }

        if ($found === []) {
            return $this->check('card_columns', 'Card data', 'pass', 'Payments and connected accounts do not store a card number or a CVC.');
        }

        return $this->check('card_columns', 'Card data', 'fail', 'A card or secret column is present: '.implode(', ', $found).'.');
    }

    private function forbidden(string $column): bool
    {
        $name = strtolower($column);

        foreach (['card_number', 'cvc', 'cvv', 'password', 'secret'] as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }

        return $name === 'pan' || str_ends_with($name, '_pan') || str_starts_with($name, 'pan_');
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function csrf(): array
    {
        $group = app('router')->getMiddlewareGroups()['web'] ?? [];

        foreach ($group as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if ($middleware === ValidateCsrfToken::class || is_subclass_of($middleware, ValidateCsrfToken::class)) {
                return $this->check('csrf', 'CSRF', 'pass', 'Form posts use Laravel\'s CSRF check.');
            }
        }

        return $this->check('csrf', 'CSRF', 'fail', 'Form posts are not using Laravel\'s CSRF check.');
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function httpOnly(): array
    {
        $only = $this->enabled(config('session.http_only'));

        return $this->check(
            'session_http_only',
            'Session cookie',
            $only ? 'pass' : 'fail',
            $only ? 'The session cookie is HTTP-only.' : 'The session cookie is not HTTP-only.',
        );
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function sameSite(): array
    {
        $value = config('session.same_site');
        $token = is_string($value) ? strtolower($value) : '';

        if ($token === 'lax' || $token === 'strict') {
            return $this->check(
                'session_same_site',
                'Session cookie',
                'pass',
                'The session cookie is SameSite '.$token.'.',
            );
        }

        return $this->check(
            'session_same_site',
            'Session cookie',
            'fail',
            'The session cookie is not SameSite lax or strict.',
        );
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function auditLog(): array
    {
        try {
            $present = Schema::hasTable('audit_logs');
        } catch (Throwable) {
            $present = false;
        }

        return $this->check(
            'audit_log',
            'Audit log',
            $present ? 'pass' : 'fail',
            $present ? 'The audit log table is present.' : 'The audit log table is missing.',
        );
    }

    private function enabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param  'pass'|'fail'  $state
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
