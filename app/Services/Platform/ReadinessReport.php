<?php

namespace App\Services\Platform;

use App\Support\SchedulerHeartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Local answers for the Section 17 security and production-readiness report.
 * Nothing here calls Stripe or writes a price, tax, fee, refund, or payment.
 */
final class ReadinessReport
{
    public function __construct(private readonly SchedulerHeartbeat $scheduler) {}

    /**
     * @return array{ready: bool, checks: list<array{key: string, label: string, state: string, detail: string, line: string}>}
     */
    public function build(): array
    {
        $checks = [
            $this->passwordHash(),
            $this->phpMail(),
            $this->appKey(),
            $this->cipher(),
            $this->rateLimits(),
            $this->queue(),
            $this->debug(),
            $this->restoreCommand(),
            $this->sessionSecure(),
            $this->https(),
            $this->scheduler(),
        ];

        $ready = collect($checks)->every(fn (array $check): bool => $check['state'] !== 'fail');

        return [
            'ready' => $ready,
            'checks' => $checks,
        ];
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function passwordHash(): array
    {
        $driver = $this->token(config('hashing.driver'));

        if ($driver === 'bcrypt') {
            return $this->check('password_hash', 'Password hashing', 'pass', 'Passwords use bcrypt.');
        }

        if ($driver === 'argon2id') {
            return $this->check('password_hash', 'Password hashing', 'pass', 'Passwords use Argon2id.');
        }

        if ($driver === 'argon' || $driver === 'argon2i') {
            return $this->check(
                'password_hash',
                'Password hashing',
                'fail',
                'Passwords use '.$driver.', which is not bcrypt or Argon2id.',
            );
        }

        return $this->check('password_hash', 'Password hashing', 'fail', 'The password hasher is not bcrypt or Argon2id.');
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function phpMail(): array
    {
        $mailer = config('mail.default');

        if ($mailer === 'mail') {
            return $this->check('php_mail', 'PHP mail()', 'fail', 'The mailer is PHP mail().');
        }

        $named = $this->token($mailer);
        $known = ['array', 'log', 'smtp', 'ses', 'postmark', 'sendmail', 'mailgun', 'resend', 'failover', 'roundrobin'];
        $detail = $named !== null && in_array($named, $known, true)
            ? 'The mailer is '.$named.'.'
            : 'The mailer is not PHP mail().';

        return $this->check('php_mail', 'PHP mail()', 'pass', $detail);
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function appKey(): array
    {
        $key = config('app.key');
        $set = is_string($key) && $key !== '';

        return $this->check(
            'app_key',
            'Application key',
            $set ? 'pass' : 'fail',
            $set ? 'An application key is set.' : 'An application key is missing.',
        );
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function cipher(): array
    {
        $cipher = $this->token(config('app.cipher'));

        if ($cipher === 'AES-256-CBC' || $cipher === 'AES-256-GCM') {
            return $this->check('cipher', 'Encryption', 'pass', 'Encryption uses '.$cipher.'.');
        }

        return $this->check('cipher', 'Encryption', 'fail', 'Encryption is not AES-256-CBC or AES-256-GCM.');
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function rateLimits(): array
    {
        $required = ['login', 'signup', 'availability', 'checkout'];
        $missing = [];

        foreach ($required as $name) {
            if (RateLimiter::limiter($name) === null) {
                $missing[] = $name;
            }
        }

        if ($missing === []) {
            return $this->check(
                'rate_limits',
                'Rate limits',
                'pass',
                'Login, public signup, availability, and checkout are rate limited.',
            );
        }

        return $this->check('rate_limits', 'Rate limits', 'fail', 'Missing '.implode(', ', $missing).'.');
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function queue(): array
    {
        $connection = $this->token(config('queue.default'));

        if ($connection !== 'database') {
            $known = ['sync', 'redis', 'sqs', 'beanstalkd', 'deferred', 'background', 'failover'];
            $detail = $connection !== null && in_array($connection, $known, true)
                ? 'The queue uses '.$connection.'.'
                : 'The queue does not use the database driver.';

            return $this->check('queue', 'Queue', 'fail', $detail);
        }

        $table = config('queue.connections.database.table');

        if (! is_string($table) || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return $this->check('queue', 'Queue', 'fail', 'The jobs table could not be checked.');
        }

        try {
            $present = Schema::hasTable($table);
        } catch (Throwable) {
            return $this->check('queue', 'Queue', 'fail', 'The jobs table could not be checked.');
        }

        if (! $present) {
            return $this->check('queue', 'Queue', 'fail', 'The jobs table is missing.');
        }

        return $this->check('queue', 'Queue', 'pass', 'The queue uses the database driver and the jobs table is present.');
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function debug(): array
    {
        $on = config('app.debug') === true;

        return $this->check('debug', 'Debug', $on ? 'fail' : 'pass', $on ? 'Debug is on.' : 'Debug is off.');
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function restoreCommand(): array
    {
        $registered = array_key_exists('reserveworx:restore-backup', Artisan::all());

        return $this->check(
            'restore_command',
            'Restore command',
            $registered ? 'pass' : 'fail',
            $registered ? 'reserveworx:restore-backup is registered.' : 'The restore command is not registered.',
        );
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function sessionSecure(): array
    {
        if (config('app.env') !== 'production') {
            return $this->check(
                'session_secure',
                'Secure session cookie',
                'note',
                'Secure cookies are checked when the app is in production.',
            );
        }

        $secure = $this->enabled(config('session.secure'));

        return $this->check(
            'session_secure',
            'Secure session cookie',
            $secure ? 'pass' : 'fail',
            $secure ? 'The session cookie is marked secure.' : 'The session cookie is not marked secure.',
        );
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function https(): array
    {
        if (config('app.env') !== 'production') {
            return $this->check('https', 'HTTPS', 'note', 'HTTPS is checked when the app is in production.');
        }

        $url = config('app.url');
        $https = is_string($url) && str_starts_with(strtolower($url), 'https://');

        return $this->check(
            'https',
            'HTTPS',
            $https ? 'pass' : 'fail',
            $https ? 'The application URL uses HTTPS.' : 'The application URL does not use HTTPS.',
        );
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function scheduler(): array
    {
        $status = $this->scheduler->status();

        if ($status === 'ok') {
            return $this->check('scheduler', 'Scheduler', 'pass', 'The scheduler has run in the last 10 minutes.');
        }

        $detail = $status === 'stale'
            ? 'The scheduler last ran more than 10 minutes ago.'
            : 'The scheduler has not been recorded.';

        return $this->check('scheduler', 'Scheduler', 'fail', $detail);
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

    private function token(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,32}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
