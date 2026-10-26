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
| `pay_at_pickup` | false | Tenant, with `settings.update` |
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
- A late fee applied from `/staff/returns` is added to `customer_total_cents` only. It is not added to `basis_cents`, `charged_basis_cents`, or `fee_cents`, and it does not write a platform-fee row. The booking fee stays the amount already charged. A later Stripe price increase is still fee'd on that increase alone. A refund still uses Section 7.7 against the basis already charged. Waiving the late fee does not refund anything.
- A damage amount filed from `/staff/returns` or the booking page is added to `customer_total_cents` and `damage_cents` only. It is not added to `basis_cents`, `charged_basis_cents`, or `fee_cents`, and it does not write a platform-fee row. The booking fee stays the amount already charged. Leaving the amount blank adds nothing. Marking the report resolved does not remove it.
- A price increase paid through Stripe is fee'd on the increase only. `POST /api/v1/bookings/{booking}/increase-checkout` prices the booking as it stands now, subtracts the quoted fee basis, and charges the difference. That basis is services, chosen add-on lines, and mandatory operator fees, minus discounts. A per-person add-on uses the party size and its stored unit price. A flat or each line does not change. Tax stays on the discounted service total. The percentage in effect at that moment applies. The fixed amount and the minimum do not. The booking must already be `deposit_paid` or `paid`, and the date, duration, or party size must differ from the quote. The paid webhook adds the increase onto the snapshot and records a second platform fee. `application_fee_cents` stays the first payment's fee.
- Extending a rental uses that same increase. The allocation is saved first. When the longer interval prices higher and the booking is already `deposit_paid` or `paid`, and the connected account can charge, the extension starts increase checkout. The booking fee is the percentage on that increase only. An unpaid or offline booking is still extended. The card charge waits until the booking is paid by card. A customer asking from the booking page is refused before the allocation when the extra time costs more and a card charge cannot be started.
- Changing party size does not charge or refund by itself. `POST /api/v1/bookings/{booking}/party-size` updates the party, the allocation, and any per-person add-on totals. The snapshot keeps the previous party size and add-on total until increase checkout or a decrease refund runs.
- Moving a booking on `/staff/schedule` does not charge or refund by itself. A new start can change the price the increase and decrease rules already calculate. A higher price is still charged with increase checkout, and the booking fee on that increase is still the percentage only. A lower price is still refunded with the proportional decrease refund. The snapshot stays until one of those runs.
- A booking started from an empty slot on `/staff/schedule` is quoted with the same direct `BookingPricer::quote` as public checkout: tax 0, no promo, and a 100 percent deposit. Pay at pickup records the offline amount, which is the customer total minus the customer booking-fee line, and no platform fee. A payment link uses the existing Stripe checkout. Fee rates are unchanged.
- A price decrease refunds the difference. `POST /api/v1/bookings/{booking}/decrease-refund` prices the booking as it stands now, including add-on lines that fell with the party size. The subtotal that fell, and any tax that fell, are refunded with reason `price_reduced`. The booking fee already charged is returned in that same proportion. The date, duration, or party size must differ from the quote. The booking must already be `deposit_paid`, `paid`, or `partially_refunded`. `charged_basis_cents` keeps the subtotal the fee was charged on, so a later partial refund still uses that denominator. `application_fee_cents` stays the first payment's fee. `basis_cents` becomes the price after the reduction.
- Offline payments: no fee, status `offline_paid`, method `offline`.
- `pay_at_pickup` adds Pay at pickup on the public review page. The recorded amount is the customer total minus the customer booking-fee line. The demo shops turn this on.
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

## Peak hours

Spec Section 7.1 names peak rates and does not say what happens when a booking crosses the peak window. The rule below is that choice.

`POST /api/v1/services/{service}/prices` accepts an optional local clock window. `local_start` and `local_end` are `HH:MM` in the booking location's timezone. Both are required together, and they must differ. The window is half-open: the start minute counts and the end minute does not. An end earlier than the start crosses midnight. `weekdays` is optional and only allowed with a clock window. The values are Carbon weekdays, `0` Sunday through `6` Saturday. A weekday or weekend `window` still applies, and every listed day must fit that window.

Each rate is a price for the whole booking, not a price per minute. `base`, `per_person`, and `duration` stay separate and still add together. For one kind, each elapsed minute uses one rate:

- A minute inside one or more clock windows uses the winning window of that kind. A date-bounded window beats one with no dates. A window with a weekday list beats one without. A weekday or weekend window beats `always`. If those are still equal, the earlier rule for that service wins. Rules are read in id order.
- Any other minute uses the rates of that kind that have no clock window. Those rates still follow the booking's start for weekday, weekend, and date bounds. They are summed, as they were before clock windows existed.
- A minute that matches no rate of that kind contributes nothing. A booking that matches no rate at all is refused. A booking that is only partly inside a peak, and has no rate for the rest of that kind, is charged for the covered share only.

The kind's charge is the minute-weighted average of those rates, rounded half-up once. A per-person rate is multiplied by the party size before that rounding. Elapsed minutes come from the UTC interval. The location timezone converts each minute. A local hour that daylight saving skips is not priced. A local hour that repeats can match the window twice. A price increase or decrease uses the booking's local start, not midnight, and writes the new `time_windows` onto the snapshot.

`time_windows` lists each clock window that won at least one minute: the kind, the amount, the weekday or weekend window, `local_start`, `local_end`, `weekdays`, the date bounds, the duration, and `minutes`.

A service variant may store `price_override_cents`. When the booking names that variant, the override replaces every open base rate before the peak windows are applied. A peak base window still wins the minutes it covers. Per-person and duration rates are unchanged. The resulting service total is the fee basis, together with add-ons and mandatory operator fees, minus discounts. The snapshot records `variant_id`, `variant_name`, and `price_override_cents`. A booking with no variant uses the service rates as before.

## Checkout

`BookingPricer` stores one pricing snapshot per booking. A second quote returns the first snapshot. The snapshot includes the fee-engine fields, the service total, the add-on total, each add-on line, the discount, the promo code, the tax rate, the priced date, the local start, the duration, and party size, `time_windows`, `tax_includes_booking_fee: false`, the charge, and the application fee. Tax is calculated on the discounted service total and is not part of the fee basis. Add-ons and equipment fees are part of the fee basis and the customer subtotal. They are not taxed, and a percent or amount promo does not reduce them. The line totals are fixed when the add-ons are chosen, except a per-person line, which is the stored unit price times the party size. A later catalog or service price change does not rewrite those lines. A party-size change rewrites only the per-person totals. Mandatory operator fees are part of the same fee basis and the customer subtotal. They are not taxed, and a promo does not reduce them. A percent fee uses the pre-discount service total, including a peak when one applies. The snapshot stores each line and `mandatory_operator_fees_cents`. A hold copies the active fees. The first quote refreshes them. A second quote keeps the snapshot. The public review page can replace that snapshot while the booking is still held and unpaid, when the customer applies a promo code. The booking fee is recalculated on the subtotal after the discount. The code's use count increases only when that booking is confirmed. A second API quote still returns the snapshot already stored. A party-size change recalculates per-person and percent lines from that snapshot, not from the live catalog, and the increase or decrease includes that change with the retotaled add-ons. Flat lines stay at the quoted total. Service revenue and the live gross total still read `services_cents`, so they do not include add-ons or these fees.

`POST /api/v1/bookings/{booking}/pricing` writes that snapshot and the current booking-fee disclosure version for a customer-paid tenant. `POST /api/v1/bookings/{booking}/offline-payment` records method `offline`, fee 0, and confirms a held booking as `offline_paid`. The recorded amount is the customer total minus the customer booking-fee line, so a customer-paid quote of $23.98 with a $0.66 booking fee is stored as $23.32. An operator-absorbed quote has no customer fee line, and the offline amount stays the customer total.

Stripe uses Accounts v2 (`ConnectAccountSpec::API_VERSION`, `2026-09-30.preview`). The connected account is `dashboard: full`, with Stripe collecting processing fees and losses, and `card_payments` requested. There is no `type: standard` and no customer configuration. Direct charges are Checkout Sessions created with the `stripe_account` option and `payment_intent_data.application_fee_amount` when the fee is positive. `payment_method_types` is omitted. `integration_identifier` is `reserveworx_` plus eight random letters.

`POST /api/v1/bookings/{booking}/pricing` accepts `deposit_percent` from 1 to 100. The stored `charge_cents` is that share of the customer total, raised when the spec requires it, and it carries the full booking fee. A paid webhook whose captured card total is still below `customer_total_cents` sets `deposit_paid`. `POST /api/v1/bookings/{booking}/balance-checkout` then charges the exact remainder with no application fee. The paid webhook marks the booking `paid` once the sum of paid payment rows reaches the customer total. It does not write a second platform-fee row for that remainder. A booking that is not `deposit_paid`, or that already has an open balance checkout, is refused.

The browser return URL does not confirm a booking. `checkout.session.completed` and `checkout.session.async_payment_succeeded` confirm only when `payment_status` is `paid` or `no_payment_required`. Events are deduped on `stripe_event_id`. A duplicate that never finished processing is retried. `application_fee.created` stores the Stripe fee id on the matching platform fee. `application_fee.refunded` stores `amount_refunded`. `GET /api/v1/fee-ledger` sums the current tenant's platform fees and lists each booking. Each fee shows what was charged and what has been refunded. Each fee refund shows the amount, the reason, the rule, and the actor. A Section 7.7 refund that returns a booking fee is listed on that booking's first fee. A platform manual refund is listed on the fee it returned. A customer refund that returns no booking fee is left out. `booking_id` limits the list to one booking in this workspace. The sums still use each fee row's stored `refunded_cents`, including an amount a webhook stored before a refund row existed. `GET /api/v1/platform/fee-revenue` is platform-admin only. It sums the same stored rows across workspaces, grouped by workspace and by the channel already stored on each row, for an inclusive UTC calendar window (`from` through `to`). `manual_refunded_cents` sums platform-issued fee refunds on the fees in that window. It does not call Stripe and it does not decide which channel a booking uses.

`GET /api/v1/platform/fee-reconciliation` is platform-admin only and requires `from` and `to`. It lists Stripe application fees created in that same UTC window and compares them with the stored fee rows. `balanced` is true only when every stored fee in the window has the same Stripe id, amount, and refunded amount, and Stripe has no extra fee in the window. A stored fee with no Stripe id, a Stripe fee that is not stored, and an amount that differs are each listed. A Stripe-only fee is tied to a workspace when its connected account is already stored. Without the platform secret the endpoint returns 409 and writes no audit row. A period of more than 2,000 Stripe fees is refused instead of reported as balanced. The stored fee report is unchanged.

Without `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET`, the gateway is `UnconfiguredStripeGateway`. Checkout returns 409. The webhook returns 400. Tests use `FakeStripeGateway` plus one real HMAC check in `StripeSdkGateway`. No live Stripe call is made.

`POST /api/v1/bookings/{booking}/refunds` runs the calculator and then Stripe. A full refund with nothing refunded before sends `refund_application_fee=true`. Every other fee refund omits that flag and creates an application fee refund for the calculator's amount. `dispute_lost` and `dispute_won` are not accepted on that request. `charge.dispute.closed` with status `lost` returns the remaining booking fee through an application fee refund, marks the booking `disputed`, and does not refund the customer. `won` and `warning_closed` record the rule and return nothing. A missing Stripe fee id, or a payment that is not stored yet, answers 503 and leaves that event unprocessed so a later delivery can finish it. The same dispute id does not return the fee twice. Without the platform secret the refund endpoint returns 409 and does not write a refund. The `booking.refunded` hook records the customer refund, the booking-fee portion of that refund, and the application-fee refund. A lost dispute does not queue that hook. Sending a receipt waits on an email provider. Receipt rendering is the disclosure partials.

`POST /api/v1/platform/fees/{fee}/refunds` is platform-administrator only. It refunds part of that one application fee back to the connected account. The customer is not refunded, the booking payment status does not change, and no Section 7.7 reason code is accepted. The reason is required free text. The same idempotency key returns the original row. A different request with that key is refused. The row is written only after Stripe accepts the call. A later Section 7.7 refund counts that amount as fee already returned on the booking's first fee, so it does not return the fee twice. The fee report's `manual_refunded_cents` is that amount. It is already included in `refunded_cents`, and net does not subtract it again.

## Cancellation policy

Spec Section 7.1 names cancellation and refund policies. Spec Section 7.7 says a customer cancellation is full, proportional, or none, and a no-show follows the tenant's no-show policy as a customer cancellation.

`PUT /api/v1/cancellation-policy` replaces the workspace's windows. `customer_cancel` is a list of `lead_minutes` and `refund_percent`. `no_show_refund_percent` is null, or 0 through 100. `settings.view` reads it. `settings.update` writes it. `/staff/settings` is the same save. The audit action is `cancellation_policy.updated`.

A guest cancel still requires `guest_cancel_before_minutes`, and it still has to be before that cutoff. The refund is chosen after that gate. The longest saved lead that is less than or equal to the whole minutes until the start wins. A lead of 0 matches any time before the start. After the start, none match. No row, or a percent of 0, issues no Stripe refund. 100% uses `customer_cancelled_full_refund` or `no_show_full_refund`. Any other percent uses the partial reason and `CancellationRefund::share` on the charged subtotal and on `tax_cents`, rounded half-up. The fee calculator then returns the booking fee in that same proportion. A share that rounds to less than one cent of subtotal does not call Stripe.

An offline payment is cancelled, or marked as a no-show, with no refund row. A card refund that Stripe refuses, including a missing platform secret, leaves the booking status unchanged. A partial refund larger than the amount still paid is refused the same way. "Nothing left to refund" still lets the status change. Staff cancel and `POST /api/v1/bookings/{booking}/cancel` stay on `operator_cancelled` and are not read from these windows. The review page and the manage page state the saved windows.

## Not in this repository

No live Stripe keys and no receipt email. The application-fee refund call exists and is covered by `FakeStripeGateway`. Settings tests cover authorization and persistence only.
