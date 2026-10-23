# Fees

Product rules D1–D8 and spec Sections 7.4–7.7. The fee calculation engine for Sections 7.4 and 7.5 lives in `app/Domain/Fees` (see [Calculation engine](#calculation-engine)). **Stripe calls, checkout disclosures, and refund execution are not implemented.** Settings are persisted and rates change through a platform-only API.

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

## Calculation engine

`App\Domain\Fees\FeeCalculator` is pure: integer cents, no Stripe calls, no database access. Callers load the rate with `FeeRate::fromTenantSetting()`, build a `FeeBasis` from line items, and store `BookingFeeQuote::toSnapshot()` in the pricing snapshot.

| Method | Rule |
|---|---|
| `feeFor(rate, basis)` | Percentage half-up, plus fixed if set, then raised to the minimum if set |
| `quote(...)` | Fee and customer total for the payer mode |
| `firstStripePayment(quote, requested)` | Full fee. Deposit ≤ fee is raised to fee + $1.00, capped at the total. Operator-absorbed fee ≥ total throws `FeeExceedsChargeException` |
| `balanceStripePayment(amount)` | No fee |
| `quoteIncrease(...)` + `priceIncreaseStripePayment(...)` | Fee on the increase |
| `offlinePayment(amount)` | No fee |

`PaymentFee::stripeApplicationFeeAmount()` returns null for a zero fee so the caller omits `application_fee_amount`.

Engineering choices the spec does not settle, pending product-owner confirmation:

- **Zero basis.** A booking with a $0 fee basis carries no fee, even when a fixed amount or minimum is enabled.
- **Increases.** Only the percentage applies to a price increase. The fixed amount and minimum are per booking and are not charged again. The increase is rounded on its own, so the fee on $10.50 plus a $10.50 increase is 32 + 32 cents, not 63.
- **Raised deposit cap.** A raised deposit never exceeds the customer total.
- **First Stripe payment after an offline payment.** The engine charges the full booking fee on whatever the caller passes as the first Stripe payment. Whether a booking that was partly paid offline should carry the full fee is open question 26.

## Who pays

- `customer_paid`: the customer sees a line item labeled exactly `Booking fee`.
- `operator_absorbed`: no customer line. The application fee is still deducted from the connected account.

Every fee must be disclosed before payment (spec Section 7.6). Disclosure copy is waiting on attorney review (open question 2). Do not ship customer-facing fee text until that review exists.

## Refunds

Spec Section 7.7 is the rule. Stripe's `refund_application_fee=true` on a partial refund returns a proportion of the whole charge, which includes tax and the fee, so it is the wrong proportion.

When refunds are implemented:

- Full refund: customer refund of the charge, and `refund_application_fee=true` so the whole application fee returns.
- Partial refund: create the customer refund without `refund_application_fee`, then `POST /v1/application_fees/{id}/refunds` for the amount computed from the spec's subtotal basis.
- Offline payments have no application fee to return.

Citations for the Stripe behavior are in [`INTEGRATIONS.md`](INTEGRATIONS.md).

## Not in this repository

No price quote endpoint, no Stripe PaymentIntent, no webhook handler, no application-fee refund, and no receipt. Settings tests cover authorization and persistence only.
