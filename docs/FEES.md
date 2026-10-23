# Fees

Product rules D1–D8 and spec Sections 7.4–7.7. **The calculation engine, Stripe calls, checkout disclosures, and refund execution are not implemented.** What exists is persistence of the settings and a platform-only API to change rates.

## What is stored

`tenant_settings` columns:

| Column | Default | Who may change it |
|---|---|---|
| `direct_fee_bps` | 300 (3%) | Platform administrator |
| `third_party_fee_bps` | 100 (1%) | Platform administrator |
| `direct_fee_fixed_cents` | 0 (disabled) | Platform administrator |
| `third_party_fee_fixed_cents` | 0 (disabled) | Platform administrator |
| `direct_fee_minimum_cents` | null (disabled) | Platform administrator |
| `third_party_fee_minimum_cents` | null (disabled) | Platform administrator |
| `fee_payer` | `customer_paid` | Tenant, with `settings.update` |
| `currency` | `USD` | Nobody through the tenant API |

Guards on the platform request, not product pricing rules: basis points 0–10000, fixed and minimum amounts 0–1_000_000 cents. A minimum of null means no minimum. A fixed amount of 0 means no fixed amount. Open question 4 asks whether a product minimum should exist. The default remains none.

Changing `fee_payer` affects future bookings only. It does not rewrite past payments.

Tenants cannot send fee-rate fields. Those requests fail validation. A client-supplied `tenant_id` is handled by middleware, not by treating it as a fee field.

## Rounding, when the engine is built

Integer cents, half up.

```text
numerator = subtotal_cents * bps
fee = intdiv(numerator, 10000)
if numerator % 10000 >= 5000: fee += 1
```

Fee basis is the subtotal: services, add-ons, and mandatory operator fees, minus discounts. Taxes are excluded. The booking fee itself is excluded.

Then apply an optional fixed amount and an optional minimum, only when those columns are enabled. Do not invent a minimum while the column is null.

## When a fee is charged

- Direct channel: `direct_fee_bps`. Third-party or API channel: `third_party_fee_bps`. Staff-initiated Stripe payments are direct under D8 until open question 6 says otherwise.
- The full fee is charged on the first Stripe payment, including a deposit.
- Later balance payments of the same booking add no fee.
- A price increase paid through Stripe is fee'd on the increase only.
- Offline payments: no fee, status `offline_paid`, method `offline`.
- If the deposit is less than or equal to the fee, raise the deposit to the fee plus $1.00.
- If the operator absorbs the fee and the fee is greater than or equal to the charge, refuse checkout. Do not invent a new customer-facing adjustment.
- If the calculated fee is 0, omit Stripe `application_fee_amount`. Stripe requires that amount to be positive and less than the charge.
- Online checkout stays blocked until the connected account has `charges_enabled`.

## Who pays

- `customer_paid`: the customer sees a line item labeled exactly `Booking fee`.
- `operator_absorbed`: no customer line. The application fee is still deducted from the connected account.

Every fee must be disclosed before payment (spec Section 7.6). The product owner chose to build the disclosure templates ahead of the attorney review. **Open question 2 still blocks launch:** an attorney must confirm the wording before real customers see it.

### Disclosure templates

- `BookingFeeRefundTerms` writes the plain-language refund summary from the Section 7.7 rows a customer can be in. Disputes are tenant-platform outcomes and are left out. The fee is always labeled `Booking fee` and never described as a card fee, surcharge, processing fee, or government charge.
- `DisclosurePublisher::currentBookingFeeRefundTerms()` returns the tenant's current `DisclosureVersion` inside that tenant's context. It publishes a new immutable version only when the text changes. Operator-absorbed tenants get none, because the customer sees no fee.
- Blade partials: `disclosures.checkout-summary` lists subtotal, booking fee, taxes, total, and the refund terms. `disclosures.receipt` adds paid, refunded, and booking fee refunded, plus the terms version. In customer-paid mode both refuse to render without refund terms.

## Refunds

Spec Section 7.7 is the rule. Stripe's `refund_application_fee=true` on a partial refund returns a proportion of the whole charge, which includes tax and the fee, so it is the wrong proportion.

When refunds are implemented:

- Full refund: customer refund of the charge, and `refund_application_fee=true` so the whole application fee returns.
- Partial refund: create the customer refund without `refund_application_fee`, then `POST /v1/application_fees/{id}/refunds` for the amount computed from the spec's subtotal basis.
- Offline payments have no application fee to return.

Citations for the Stripe behavior are in [`INTEGRATIONS.md`](INTEGRATIONS.md).

## Not in this repository

No price quote endpoint, no Stripe PaymentIntent, no webhook handler, no application-fee refund, and no receipt. Settings tests cover authorization and persistence only.
