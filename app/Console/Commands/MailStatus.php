<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MailStatus extends Command
{
    protected $signature = 'reserveworx:mail-status';

    protected $description = 'Print whether outbound mail can leave the server. This command does not send mail.';

    public function handle(): int
    {
        $mailer = config('mail.default');
        $mailer = is_string($mailer) ? $mailer : '';
        $from = config('mail.from.address');
        $fromSet = is_string($from) && $from !== '' && ! str_ends_with($from, '@example.com');

        $this->info('mail_mailer='.$mailer);
        $this->info('mail_from_set='.($fromSet ? 'yes' : 'no'));
        $this->info('mail_delivers='.($this->delivers($mailer) ? 'yes' : 'no'));
        $this->info('mail_sent=0');

        return self::SUCCESS;
    }

    private function delivers(string $mailer): bool
    {
        if ($mailer === '' || in_array($mailer, ['log', 'array'], true)) {
            return false;
        }

        if ($mailer !== 'smtp') {
            return true;
        }

        $host = config('mail.mailers.smtp.host');
        $username = config('mail.mailers.smtp.username');

        return is_string($host) && $host !== '' && $host !== '127.0.0.1'
            && is_string($username) && $username !== '';
    }
}
