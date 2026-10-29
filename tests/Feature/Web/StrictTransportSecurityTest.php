<?php

namespace Tests\Feature\Web;

use App\Http\StrictTransport;
use App\Models\PlatformAdministrator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StrictTransportSecurityTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_https_responses_stay_on_https_and_write_nothing(): void
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $workspace = $this->workspace('dock.test');
        $before = $this->counts();

        $https = [
            'https://app.reserveworx.com/platform/login',
            'https://app.reserveworx.com/up',
            'https://dock.test/staff/login',
            'https://dock.test/book/missing-token',
        ];

        foreach ($https as $url) {
            $response = $this->get($url);
            $response->assertHeader(StrictTransport::HEADER, StrictTransport::VALUE);
            $this->assertSame(
                [StrictTransport::VALUE],
                $response->headers->all(StrictTransport::HEADER),
                $url,
            );
            $response->assertHeaderMissing('X-Frame-Options');
        }

        $http = [
            'http://app.reserveworx.com/platform/login',
            'http://dock.test/staff/login',
            'http://dock.test/book/missing-token',
        ];

        foreach ($http as $url) {
            $this->get($url)->assertHeaderMissing(StrictTransport::HEADER);
        }

        $this->artisan('reserveworx:security')
            ->expectsOutputToContain('hsts=pass')
            ->expectsOutputToContain('secure=yes')
            ->doesntExpectOutputToContain(StrictTransport::VALUE)
            ->doesntExpectOutputToContain('includeSubDomains')
            ->assertSuccessful();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/security')
            ->assertOk()
            ->assertHeaderMissing(StrictTransport::HEADER)
            ->assertSee('HTTPS responses tell the browser to stay on HTTPS.')
            ->assertSee('An HTTPS response has to tell the browser to stay on HTTPS.');

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/help')
            ->assertOk()
            ->assertSee('tells the browser to stay on HTTPS');

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('dock.test', '/staff/help'))
            ->assertOk()
            ->assertDontSee('tells the browser to stay on HTTPS');

        $this->assertSame($before, $this->counts());
    }

    public function test_a_missing_https_header_is_not_secure_and_the_error_stays_hidden(): void
    {
        $before = $this->counts();

        $this->partialMock(StrictTransport::class, function ($mock): void {
            $mock->shouldReceive('registered')->andThrow(new RuntimeException('canary-hsts-secret'));
        });

        $this->artisan('reserveworx:security')
            ->expectsOutputToContain('hsts=fail')
            ->expectsOutputToContain('secure=no')
            ->doesntExpectOutputToContain('canary-hsts-secret')
            ->doesntExpectOutputToContain(StrictTransport::VALUE)
            ->doesntExpectOutputToContain('RuntimeException')
            ->assertFailed();

        $this->actingAs($this->admin())
            ->get('http://app.reserveworx.com/platform/security')
            ->assertOk()
            ->assertSee('Not secure')
            ->assertSee('HTTPS responses do not tell the browser to stay on HTTPS.')
            ->assertDontSee('canary-hsts-secret')
            ->assertDontSee(StrictTransport::VALUE);

        $this->assertSame($before, $this->counts());
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);

        return $admin;
    }

    /**
     * @return array{payments: int, refunds: int, fees: int, fee_refunds: int, prices: int, taxes: int, snapshots: int, audits: int}
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
}
