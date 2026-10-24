<?php

namespace Tests\Feature;

use App\Models\WidgetToken;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_the_platform_host_renders_the_placeholder(): void
    {
        $this->get('http://localhost/')
            ->assertOk()
            ->assertSee('ReserveWorx')
            ->assertDontSee('bookings');
    }

    public function test_the_health_endpoint_responds_on_the_platform_host(): void
    {
        $this->get('http://localhost/up')->assertOk();
    }

    public function test_a_tenant_domain_root_is_that_workspaces_booking_page(): void
    {
        $this->workspace('dock.test', tenantAttributes: ['name' => 'Dock Works']);
        $this->workspace('beta.test', tenantAttributes: ['name' => 'Beta Works']);

        $home = $this->get($this->tenantUrl('dock.test', '/'));
        $home->assertOk()
            ->assertSee('Dock Works bookings', false)
            ->assertSee('<h1>Dock Works</h1>', false)
            ->assertSee('Choose a service')
            ->assertDontSee('Beta Works')
            ->assertDontSee('Team sign in');
        $this->assertStringNotContainsString('laravel_session', (string) $home->headers->get('set-cookie'));

        $plain = $this->hostedToken($home->getContent());
        $this->get($this->tenantUrl('dock.test', '/book/'.$plain))
            ->assertOk()
            ->assertSee('<h1>Dock Works</h1>', false);
        $this->get($this->tenantUrl('beta.test', '/book/'.$plain))->assertNotFound();

        $beta = $this->get($this->tenantUrl('beta.test', '/'));
        $beta->assertOk()
            ->assertSee('<h1>Beta Works</h1>', false)
            ->assertDontSee('Dock Works');
        $this->get($this->tenantUrl('dock.test', '/book/'.$this->hostedToken($beta->getContent())))->assertNotFound();

        $this->get($this->tenantUrl('dock.test', '/staff/login'))
            ->assertOk()
            ->assertSee('Team sign in');
    }

    public function test_revoking_the_hosted_token_does_not_take_down_the_domain_page(): void
    {
        $this->workspace('dock.test', tenantAttributes: ['name' => 'Dock Works']);
        $first = $this->get($this->tenantUrl('dock.test', '/'))->assertOk();
        $plain = $this->hostedToken($first->getContent());

        $token = WidgetToken::query()->withoutGlobalScope(TenantScope::class)
            ->where('token_hash', hash('sha256', $plain))
            ->firstOrFail();
        $token->forceFill(['revoked_at' => now()])->save();

        $this->get($this->tenantUrl('dock.test', '/book/'.$plain))->assertNotFound();
        $again = $this->get($this->tenantUrl('dock.test', '/'))->assertOk()->assertSee('<h1>Dock Works</h1>', false);
        $this->assertSame($plain, $this->hostedToken($again->getContent()));
        $this->get($this->tenantUrl('dock.test', '/book/'.$plain))->assertOk();
    }

    public function test_an_unverified_suspended_or_unknown_host_does_not_render_the_booking_page(): void
    {
        $pending = $this->workspace('pending.test', tenantAttributes: ['name' => 'Pending Works']);
        $pending['domain']->forceFill(['verified_at' => null])->save();
        $this->get($this->tenantUrl('pending.test', '/'))->assertNotFound()->assertDontSee('Pending Works');

        $this->workspace('suspended.test', tenantAttributes: ['name' => 'Suspended Works', 'status' => 'suspended']);
        $this->get($this->tenantUrl('suspended.test', '/'))->assertForbidden()->assertDontSee('Suspended Works');

        $this->workspace('closed.test', tenantAttributes: ['name' => 'Closed Works', 'status' => 'terminated']);
        $this->get($this->tenantUrl('closed.test', '/'))->assertForbidden()->assertDontSee('Closed Works');

        $this->get('http://unknown.test/')->assertNotFound();
    }

    private function hostedToken(string|false $html): string
    {
        $this->assertIsString($html);
        $this->assertSame(1, preg_match('#/book/(hosted_[a-f0-9]{64})/#', $html, $matches));

        return $matches[1];
    }
}
