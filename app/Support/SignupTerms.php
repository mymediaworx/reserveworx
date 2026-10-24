<?php

namespace App\Support;

/**
 * The terms shown on public signup. The version is the date the product owner
 * approved self-serve signup. It is not an attorney-reviewed agreement.
 */
final class SignupTerms
{
    public const VERSION = '2026-10-24';

    public static function body(): string
    {
        return 'I agree to create this workspace on ReserveWorx. ReserveWorx charges a booking fee on card payments: 3% on direct bookings and 1% on third-party bookings, unless a platform administrator sets a different rate for this workspace. There is no subscription. The fee is shown to the customer before payment when the workspace uses customer-paid mode, which is the default. Card payments stay off until Stripe can charge the connected account.';
    }
}
