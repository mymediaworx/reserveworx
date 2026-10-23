<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffAuthTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    private function password(User $user): void
    {
        $user->forceFill(['password' => bcrypt('correct-horse-battery')])->save();
    }

    public function test_the_login_page_shows_the_workspace_name(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);

        $this->get($this->tenantUrl('alpha.test', '/staff/login'))
            ->assertOk()
            ->assertSee('Alpha Kayaks')
            ->assertSee('Team sign in');
    }

    public function test_a_member_signs_in_and_lands_on_today(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->password($alpha['user']);

        $this->post($this->tenantUrl('alpha.test', '/staff/login'), [
            'email' => $alpha['user']->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/staff');

        $this->assertAuthenticatedAs($alpha['user']);
        $this->get($this->tenantUrl('alpha.test', '/staff'))->assertOk()->assertSee('Today’s schedule', false);
    }

    public function test_a_member_of_another_workspace_cannot_sign_in_here(): void
    {
        $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $this->password($beta['user']);

        $this->from($this->tenantUrl('alpha.test', '/staff/login'))
            ->post($this->tenantUrl('alpha.test', '/staff/login'), [
                'email' => $beta['user']->email,
                'password' => 'correct-horse-battery',
            ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_wrong_password_gets_the_same_message(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->password($alpha['user']);

        $response = $this->post($this->tenantUrl('alpha.test', '/staff/login'), [
            'email' => $alpha['user']->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors(['email' => 'That email and password do not match a team member of this workspace.']);
        $this->assertGuest();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->workspace('alpha.test');

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings'))->assertRedirect('/staff/login');
    }

    public function test_a_signed_in_user_without_a_seat_in_this_workspace_is_refused(): void
    {
        $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');

        $this->actingAs($beta['user'])
            ->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertForbidden();
    }

    public function test_the_staff_area_does_not_exist_on_the_platform_host(): void
    {
        $alpha = $this->workspace('alpha.test');

        $this->actingAs($alpha['user'])->get('http://app.reserveworx.com/staff')->assertNotFound();
        $this->get('http://app.reserveworx.com/staff/login')->assertNotFound();
    }

    public function test_sign_out_ends_the_session(): void
    {
        $alpha = $this->workspace('alpha.test');

        $this->actingAs($alpha['user'])
            ->post($this->tenantUrl('alpha.test', '/staff/logout'))
            ->assertRedirect('/staff/login');

        $this->assertGuest();
    }

    public function test_the_stylesheet_is_served_with_a_long_cache(): void
    {
        $response = $this->get('http://app.reserveworx.com/ui/app.css')->assertOk();

        $this->assertStringContainsString('text/css', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }
}
