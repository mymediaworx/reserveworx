<?php

namespace App\Domain\Fees;

/**
 * Booking channel that decides which tenant fee rate applies (spec Section 7.4).
 *
 * Staff-initiated Stripe payments are Direct under D8 until open question 6 says otherwise.
 */
enum FeeChannel: string
{
    /** Tenant widget, tenant site, or staff-initiated Stripe payment. Default 3%. */
    case Direct = 'direct';

    /** Third-party, reseller, or API booking. Default 1%. */
    case ThirdParty = 'third_party';
}
