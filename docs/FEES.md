# Fees

Product rules D1–D8 and spec Sections 7.4–7.8. Michael approved keeping the fee rules merged in #11, #13, and #15 on October 23, 2026 (D11). Those commits stay. The fee calculation engine for Sections 7.4 and 7.5 lives in `app/Domain/Fees` (see [Calculation engine](#calculation-engine)). Checkout disclosures are published with a pricing snapshot. Stripe checkout, refunds, and webhooks are wired and stay inert until the platform secret exists. Settings are persisted and rates change through a platform-only API.

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
- Online checkout stays blocked until the connected account's `card_payments` capability status is `active`. Accounts v2 does not use `charges_enabled`.

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

### Refund calculator

`App\Domain\Fees\Refunds\FeeRefundCalculator` turns each Section 7.7 row into amounts. It is pure: no Stripe calls and no database access. Input is a `FeeRefundState` (subtotal, fee charged, amount paid through Stripe, and what has already been refunded) plus a `RefundReason`. Output is a `FeeRefund` with the customer refund, the booking fee inside it, the application fee refund, the reason code, the rule applied, and which Stripe mechanism matches.

| Reason codes | Rule (7.7 row) | Amounts |
|---|---|---|
| `operator_cancelled` | Operator cancels | Everything still refundable |
| `platform_oversell`, `platform_payment_after_hold_expiry`, `platform_duplicate_charge`, `platform_error` | Platform or system fault | Everything still refundable |
| `customer_cancelled_full_refund` | Customer full refund | Everything still refundable |
| `customer_cancelled_partial_refund` | Customer partial refund | Fee in proportion to the subtotal refunded |
| `customer_cancelled_no_refund` | Customer no refund | Nothing |
| `price_reduced` | Lower price | Fee in proportion to the subtotal reduction |
| `dispute_lost` | Dispute lost | Remaining fee to the tenant. The customer is handled by the dispute |
| `dispute_won` | Dispute won | Nothing |
| `no_show_full_refund`, `no_show_partial_refund`, `no_show_no_refund` | No-show | Same as the matching customer cancellation |

Proportional fee refunds are computed from the **cumulative** subtotal refunded and rounded half-up once, minus fee already refunded. Any sequence of partial refunds that adds up to the whole subtotal returns exactly the whole fee, with no rounding drift. The caller supplies the tax to refund. Section 7.7 does not say how tax is split.

`stripeMethod` is `refund_application_fee_flag` only for a full refund with nothing refunded before. Every other fee refund is `application_fee_refund` for the exact amount. The calculator works at booking level. Splitting a refund across a deposit charge and a balance charge is the Stripe layer's job. The fee sits on the first charge.

## Checkout

`BookingPricer` stores one pricing snapshot per booking. A second quote returns the first snapshot. The snapshot includes the fee-engine fields, the service total, the discount, the promo code, `tax_includes_booking_fee: false`, the charge, and the application fee. Tax is calculated on the discounted service total and is not part of the fee basis.

`POST /api/v1/bookings/{booking}/pricing` writes that snapshot and the current booking-fee disclosure version for a customer-paid tenant. `POST /api/v1/bookings/{booking}/offline-payment` records method `offline`, fee 0, and confirms a held booking as `offline_paid`.

Stripe uses Accounts v2 (`ConnectAccountSpec::API_VERSION`, `2026-09-30.preview`). The connected account is `dashboard: full`, with Stripe collecting processing fees and losses, and `card_payments` requested. There is no `type: standard` and no customer configuration. Direct charges are Checkout Sessions created with the `stripe_account` option and `payment_intent_data.application_fee_amount` when the fee is positive. `payment_method_types` is omitted. `integration_identifier` is `reserveworx_` plus eight random letters.

The browser return URL does not confirm a booking. `checkout.session.completed` and `checkout.session.async_payment_succeeded` confirm only when `payment_status` is `paid` or `no_payment_required`. Events are deduped on `stripe_event_id`. A duplicate that never finished processing is retried. `application_fee.created` stores the Stripe fee id on the matching platform fee. `application_fee.refunded` stores `amount_refunded`. `GET /api/v1/fee-ledger` sums the current tenant's platform fees.

Without `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET`, the gateway is `UnconfiguredStripeGateway`. Checkout returns 409. The webhook returns 400. Tests use `FakeStripeGateway` plus one real HMAC check in `StripeSdkGateway`. No live Stripe call is made.

`POST /api/v1/bookings/{booking}/refunds` runs the calculator and then Stripe. A full refund with nothing refunded before sends `refund_application_fee=true`. Every other fee refund omits that flag and creates an application fee refund for the calculator's amount. `charge.dispute.closed` uses `dispute_lost` or `dispute_won`. Without the platform secret the endpoint returns 409 and does not write a refund. Receipt rendering is the disclosure partials; sending a receipt waits on an email provider. A platform-only manual fee refund, separate from these reason codes, is not built.

## Not in this repository

No live Stripe keys and no receipt email. The application-fee refund call exists and is covered by `FakeStripeGateway`. Settings tests cover authorization and persistence only.
