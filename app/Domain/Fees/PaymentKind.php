<?php

namespace App\Domain\Fees;

enum PaymentKind: string
{
    /** First Stripe payment for the booking. Carries the full booking fee, deposits included. */
    case FirstStripePayment = 'first_stripe_payment';

    /** A later Stripe payment of the same booking price. No additional fee. */
    case BalanceStripePayment = 'balance_stripe_payment';

    /** A Stripe payment for a price increase. Fee on the increase only. */
    case PriceIncreaseStripePayment = 'price_increase_stripe_payment';

    /** Cash, check, external terminal, or bank transfer. Never carries a fee (D8). */
    case Offline = 'offline';
}
