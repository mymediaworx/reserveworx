<?php

namespace Tests\Feature\Web;

use App\Support\SecureSessionCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class SecureSessionCookieTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    private mixed $originalSecure = null;

    private string $originalEnv = 'testing';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalSecure = config('session.secure');
        $env = config('app.env');
        $this->originalEnv = is_string($env) && $env !== '' ? $env : 'testing';
    }

    protected function tearDown(): void
    {
        config([
            'app.env' => $this->originalEnv,
            'session.secure' => $this->originalSecure,
        ]);
        $this->restoreEnvironment();

        parent::tearDown();
    }

    public function test_production_marks_an_unset_session_cookie_secure_and_writes_nothing(): void
    {
        $this->workspace('alpha.test');
        $before = $this->counts();

        config(['app.env' => 'production', 'session.secure' => null]);
        app(SecureSessionCookie::class)->apply();
        $this->assertTrue(config('session.secure'));

        $platform = $this->get('http://app.reserveworx.com/platform/login');
        $staff = $this->get($this->tenantUrl('alpha.test', '/staff/login'));

        $platform->assertOk();
        $staff->assertOk();
        $this->assertTrue($this->sessionCookie($platform)->isSecure());
        $this->assertTrue($this->sessionCookie($staff)->isSecure());
        $this->assertTrue($this->sessionCookie($platform)->isHttpOnly());
        $this->assertTrue($this->sessionCookie($staff)->isHttpOnly());
        $this->assertSame('lax', strtolower($this->sessionCookie($platform)->getSameSite()));
        $this->assertSame($before, $this->counts());

        config(['app.env' => 'testing', 'session.secure' => null]);
        app(SecureSessionCookie::class)->apply();
        $this->assertNull(config('session.secure'));

        $local = $this->get('http://app.reserveworx.com/platform/login');
        $local->assertOk();
        $this->assertFalse($this->sessionCookie($local)->isSecure());

        config(['app.env' => 'production', 'session.secure' => false]);
        app(SecureSessionCookie::class)->apply();
        $this->assertFalse(config('session.secure'));

        $off = $this->get('http://app.reserveworx.com/platform/login');
        $off->assertOk();
        $this->assertFalse($this->sessionCookie($off)->isSecure());
        $this->assertSame($before, $this->counts());
    }

    public function test_the_session_config_defaults_secure_cookies_to_production_only(): void
    {
        $repository = Env::getRepository();
        $repository->clear('APP_ENV');
        $repository->clear('SESSION_SECURE_COOKIE');
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';
        putenv('SESSION_SECURE_COOKIE');
        unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);

        $production = require config_path('session.php');
        $this->assertTrue($production['secure']);

        $repository->clear('APP_ENV');
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';

        $testing = require config_path('session.php');
        $this->assertFalse($testing['secure']);

        $repository->clear('APP_ENV');
        $repository->clear('SESSION_SECURE_COOKIE');
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';
        putenv('SESSION_SECURE_COOKIE=false');
        $_ENV['SESSION_SECURE_COOKIE'] = 'false';
        $_SERVER['SESSION_SECURE_COOKIE'] = 'false';

        $explicit = require config_path('session.php');
        $this->assertFalse($explicit['secure']);
    }

    private function sessionCookie(TestResponse $response): Cookie
    {
        $name = config('session.cookie');
        $this->assertIsString($name);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        $this->fail('The session cookie was not set.');
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'payments' => DB::table('payments')->count(),
            'refunds' => DB::table('refunds')->count(),
            'fees' => DB::table('platform_fees')->count(),
            'fee_refunds' => DB::table('platform_fee_refunds')->count(),
            'prices' => DB::table('price_rules')->count(),
            'taxes' => DB::table('location_tax_rules')->count(),
            'snapshots' => DB::table('pricing_snapshots')->count(),
            'audits' => DB::table('audit_logs')->count(),
        ];
    }

    private function restoreEnvironment(): void
    {
        $repository = Env::getRepository();
        $repository->clear('APP_ENV');
        $repository->clear('SESSION_SECURE_COOKIE');
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        putenv('SESSION_SECURE_COOKIE');
        unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);
    }
}
