<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Closure;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

final class PasswordResets
{
    public const REQUEST_STATUS = 'If that email can sign in here, we sent a reset link. It expires in 60 minutes.';

    public const INVALID_STATUS = 'This reset link is invalid or has expired.';

    /**
     * @param  Closure(User): bool  $eligible
     */
    public function request(string $email, Closure $eligible, string $path): void
    {
        Password::sendResetLink(['email' => strtolower($email)], function (CanResetPassword $notifiable, string $token) use ($eligible, $path): string {
            if (! $notifiable instanceof User || ! $eligible($notifiable)) {
                DB::table('password_reset_tokens')->where('email', $notifiable->getEmailForPasswordReset())->delete();

                return Password::INVALID_USER;
            }

            $url = url($path.'/'.$token).'?'.http_build_query(['email' => $notifiable->getEmailForPasswordReset()]);

            try {
                $notifiable->notify(new ResetPasswordLink($url));
            } catch (Throwable $exception) {
                report($exception);
            }

            return Password::RESET_LINK_SENT;
        });
    }

    /**
     * @param  array{email: string, password: string, password_confirmation: string, token: string}  $credentials
     * @param  Closure(User): bool  $eligible
     */
    public function reset(array $credentials, Closure $eligible): string
    {
        try {
            return Password::reset($credentials, function (CanResetPassword $notifiable, string $password) use ($eligible): void {
                if (! $notifiable instanceof User || ! $eligible($notifiable)) {
                    throw new PasswordResetRefused('This reset link is invalid or has expired.');
                }

                $notifiable->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($notifiable));
            });
        } catch (PasswordResetRefused) {
            return Password::INVALID_TOKEN;
        }
    }
}
