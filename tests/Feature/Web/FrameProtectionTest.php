<?php

namespace Tests\Feature\Web;

use App\Livewire\Platform\Security;
use App\Models\PlatformAdministrator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class FrameProtectionTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_sign_in_pages_cannot_be_framed_and_the_booking_page_can(): void
    {
        $this->workspace('dock.test', tenantAttributes: ['name' => 'Dock Works']);
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $before = $this->counts();

        $this->assertDenied($this->get($this->tenantUrl('dock.test', '/staff/login'))->assertOk());
        $this->assertDenied($this->get($this->tenantUrl('dock.test', '/admin/login'))->assertRedirect('/staff/login'));
        $this->assertDenied($this->get($this->tenantUrl('dock.test', '/account'))->assertOk());
        $this->assertDenied($this->get('http://app.reserveworx.com/platform/login')->assertOk());
        $this->assertDenied($this->get('http://app.reserveworx.com/signup')->assertOk());

        $home = $this->get($this->tenantUrl('dock.test', '/'))->assertOk()->assertSee('<h1>Dock Works</h1>', false);
        $this->assertEmbeddable($home);
        $plain = $this->hostedToken($home->getContent());
        $this->assertEmbeddable($this->get($this->tenantUrl('dock.test', '/book/'.$plain))->assertOk());

        $this->artisan('reserveworx:security')
            ->expectsOutputToContain('framing=pass')
            ->expectsOutputToContain('secure=yes')
            ->doesntExpectOutputToContain('canary-frame-secret')
            ->assertSuccessful();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/security')
            ->assertOk()
            ->assertSee('Sign-in pages cannot be framed. The booking page can.')
            ->assertSee('data-check="framing"', false)
            ->assertSee('data-state="pass"', false)
            ->assertDontSee('canary-frame-secret');

        Livewire::actingAs($admin)->test(Security::class)
            ->assertSee('Sign-in pages cannot be framed. The booking page can.');

        $this->assertSame($before, $this->counts());
    }

    private function assertDenied(TestResponse $response): void
    {
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
    }

    private function assertEmbeddable(TestResponse $response): void
    {
        $this->assertFalse($response->headers->has('X-Frame-Options'));
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('frame-ancestors', $csp);
    }

    private function hostedToken(string|false $html): string
    {
        $this->assertIsString($html);
        $this->assertSame(1, preg_match('#/book/(hosted_[a-f0-9]{64})/#', $html, $matches));

        return $matches[1];
    }

    /**
     * @return array{payments: int, refunds: int, fees: int, fee_refunds: int, prices: int, taxes: int, snapshots: int}
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
        ];
    }
}
