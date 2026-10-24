<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MailStatusTest extends TestCase
{
    public function test_the_log_mailer_is_reported_as_not_delivering_and_nothing_is_sent(): void
    {
        config([
            'mail.default' => 'log',
            'mail.from.address' => 'hello@example.com',
            'mail.mailers.smtp.password' => 'super-secret-mail',
        ]);

        $this->artisan('reserveworx:mail-status')
            ->expectsOutputToContain('mail_mailer=log')
            ->expectsOutputToContain('mail_from_set=no')
            ->expectsOutputToContain('mail_delivers=no')
            ->expectsOutputToContain('mail_sent=0')
            ->assertSuccessful();

        Artisan::call('reserveworx:mail-status');
        $this->assertStringNotContainsString('super-secret-mail', Artisan::output());
    }

    public function test_smtp_without_a_real_host_does_not_count_as_delivering(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.username' => 'sender',
            'mail.from.address' => 'hello@reserveworx.com',
        ]);

        $this->artisan('reserveworx:mail-status')
            ->expectsOutputToContain('mail_delivers=no')
            ->expectsOutputToContain('mail_from_set=yes')
            ->expectsOutputToContain('mail_sent=0')
            ->assertSuccessful();
    }

    public function test_smtp_with_a_host_and_username_counts_as_delivering(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.username' => 'sender',
            'mail.from.address' => 'hello@reserveworx.com',
        ]);

        $this->artisan('reserveworx:mail-status')
            ->expectsOutputToContain('mail_mailer=smtp')
            ->expectsOutputToContain('mail_delivers=yes')
            ->assertSuccessful();
    }
}
