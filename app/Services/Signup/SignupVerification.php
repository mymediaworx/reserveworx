<?php

namespace App\Services\Signup;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifySignupEmail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Sends the signed link that marks the signup owner's email verified.
 */
final class SignupVerification
{
    public function url(User $user, Tenant $tenant): string
    {
        return URL::temporarySignedRoute(
            'signup.verify',
            now()->addMinutes(60),
            [
                'user' => $user->id,
                'tenant' => $tenant->id,
                'hash' => sha1($user->email),
            ],
        );
    }

    public function send(User $user, Tenant $tenant): void
    {
        try {
            $user->notify(new VerifySignupEmail($this->url($user, $tenant), $tenant->name));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
