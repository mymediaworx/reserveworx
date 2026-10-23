<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_log_in_and_out_with_a_token(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $login = $this->postJson('http://localhost/api/v1/login', [
            'email' => 'owner@example.com',
            'password' => 'correct-horse-battery',
        ])->assertOk();

        $token = $login->json('token');
        $this->assertIsString($token);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('http://localhost/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'owner@example.com');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('http://localhost/api/v1/logout')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('http://localhost/api/v1/me')
            ->assertUnauthorized();

        $this->assertTrue(
            AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'auth.login')->where('actor_user_id', $user->id)->exists(),
        );
    }

    public function test_a_failed_login_does_not_record_the_password(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);

        $this->postJson('http://localhost/api/v1/login', [
            'email' => 'owner@example.com',
            'password' => 'not-the-password',
        ])->assertUnprocessable();

        $log = AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'auth.login_failed')->first();

        $this->assertNotNull($log);
        $this->assertStringNotContainsString('not-the-password', (string) json_encode($log->properties));
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'limited@example.com']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('http://localhost/api/v1/login', [
                'email' => 'limited@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('http://localhost/api/v1/login', [
            'email' => 'limited@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }
}
