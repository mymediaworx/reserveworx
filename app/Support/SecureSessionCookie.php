<?php

namespace App\Support;

/**
 * Marks the session cookie secure in production when the setting is absent.
 * An explicit true or false is left alone. Nothing here writes a price,
 * a payment, a tax, a fee, or a refund.
 */
final class SecureSessionCookie
{
    public function apply(): void
    {
        if ($this->isExplicit(config('session.secure'))) {
            return;
        }

        if (config('app.env') === 'production') {
            config(['session.secure' => true]);
        }
    }

    private function isExplicit(mixed $value): bool
    {
        if (is_bool($value) || is_int($value)) {
            return true;
        }

        return is_string($value) && trim($value) !== '';
    }
}
