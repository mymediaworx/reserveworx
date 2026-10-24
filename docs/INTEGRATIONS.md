# Integrations

Research for Stripe Connect and GoHighLevel, retrieved October 23, 2026 from the official docs linked below. No live API calls were made. Nothing in this file is implemented except empty `STRIPE_*` and `GHL_*` keys in `.env.example`. Do not copy sample client secrets from HighLevel's OAuth examples into this repository.

Payment state, when it exists, will come only from verified Stripe webhooks. A browser redirect is not payment proof. A GoHighLevel outage must not change bookings, payments, or inventory (spec Section 1.3 and D10). The outbox stores events and refuses a different workspace. It does not call HighLevel. Marketplace OAuth is still unapproved.

## Stripe Connect

Decisions D1–D8: Standard accounts, direct charges, Stripe-owned processing pricing, application fee only, no subscription.

### Direct charges

- Direct charges are created on the connected account. The platform sends the `Stripe-Account` header. The payment appears on that account. Direct charges require the `card_payments` capability.
  - https://docs.stripe.com/connect/direct-charges
  - https://docs.stripe.com/connect/charges
- `application_fee_amount` must be a positive integer less than the charge. A calculated fee of 0 must omit the field rather than send 0. Stripe creates the fee asynchronously. Listen for `application_fee.created`. There is no extra Stripe fee on the application fee itself.
  - https://docs.stripe.com/connect/direct-charges

### Who collects Stripe's processing fee

https://docs.stripe.com/connect/direct-charges-fee-payer-behavior

If the connected account is the fee payer (`controller.fees.payer` is `account`, or the fees collector is Stripe), the application fee must be only BrandWorx's booking fee. That matches D1. If the platform collects fees, the application fee has to include Stripe's processing fee as well, or the platform pays it. Confirm the connected account's fee collector before any charge is written. Accounts v2 exposes this as `defaults.responsibilities.fees_collector`. This repository does not guess which one the BrandWorx platform account uses.

Online checkout stays blocked until `charges_enabled` is true.

### Webhooks

https://docs.stripe.com/connect/webhooks

Connected-account events need a Connect webhook endpoint (`connect=true`). Those events include a top-level `account` property. Check `livemode`. Relevant types from https://docs.stripe.com/api/events/types:

- `application_fee.created`
- `application_fee.refunded` (includes partial refunds)
- `application_fee.refund.updated`
- `charge.dispute.closed` (status becomes `lost`, `warning_closed`, or `won`)

`GET /v1/application_fees` lists the fees the platform collected. `created[gte]` and `created[lt]` bound the window, `limit` is at most 100, and `starting_after` pages the rest. Each fee has `id`, `amount`, `amount_refunded`, and `account` (the connected account the fee was taken from). https://docs.stripe.com/api/application_fees/list retrieved October 24, 2026. The PHP SDK types `created` as `array{gt, gte, lt, lte}|int` on `ApplicationFee::all`.

The API version is not pinned. On October 23, 2026 the Create-a-refund page chrome showed `2026-09-30.endive`. Other version names were visible in docs navigation (`2026-01-28.clover`, `2026-03-25.preview`). Pin the version in code when payments are implemented, not before.

### Refunds and the fee

https://docs.stripe.com/connect/saas/tasks/refunds-disputes

Refunding a charge does not automatically return the application fee.

- `refund_application_fee=true` on a full refund returns the entire application fee.
- On a partial refund, the same flag returns a proportional amount of the **charge**. Stripe's documented example is a $100 charge with a $5 application fee: refunding $40 returns $2 of the fee. That proportion includes tax and the booking fee inside the charge. Spec Section 7.7 uses the booking subtotal, which excludes tax and the fee. Partial refunds must not set `refund_application_fee=true`.

Implementation shape, not built:

1. Create the customer Refund without that flag. https://docs.stripe.com/api/refunds/create
2. `POST /v1/application_fees/{id}/refunds` for the amount computed from Section 7.7. https://docs.stripe.com/api/fee_refunds/create

Full refunds use `refund_application_fee=true`, which matches the spec for a full return.

Deposit rule from the spec, not from Stripe: if the deposit is less than or equal to the fee, raise the deposit to the fee plus $1.00. If the operator absorbs the fee and the fee is greater than or equal to the charge, refuse checkout.

Never store card numbers or CVCs. Idempotency keys belong on mutating payment and booking requests.

## GoHighLevel

Two supported auth types are documented: a Private Integration Token, and OAuth 2.0. v1 API keys are end of life. Do not build on them.

Sources used:

- https://marketplace.gohighlevel.com/docs/oauth/GettingStarted
- https://marketplace.gohighlevel.com/docs/Authorization/PrivateIntegrationsToken
- https://marketplace.gohighlevel.com/docs/oauth/FAQ
- https://marketplace.gohighlevel.com/docs/webhook/WebhookIntegrationGuide
- HighLevel API v3 reference for contacts upsert and location tokens (marketplace docs, Version `v3`, released June 11, 2026)

### Private Integration Token

A PIT is a static scoped token generated in the UI. It does not refresh. Official guidance describes it for internal use, when webhooks are not needed, and for one sub-account at a time. Requests send `Authorization: Bearer` and a `Version` header. That is a poor fit for one marketplace app installed into many tenant locations, so it is not the proposed primary model.

### OAuth

Authorization-code token exchange is `POST https://services.leadconnectorhq.com/oauth/token`. Documented access-token lifetime is about 24 hours (`expires_in` 86399 or 86400). The refresh token is valid for about a year or until it is used, and then it rotates. User types are Company (agency) and Location.

Location tokens: an older guide shows `POST /oauth/locationToken`. The v3 reference shows `POST /oauth/location-token`. Both spellings were present on October 23, 2026. Do not pick one in code until a live call confirms which path the chosen app version accepts.

Version header: examples on the OAuth pages still send `Version: 2021-07-28`. The versioning page says the current version is `v3`, released June 11, 2026, supported until a date that was still TBD, and that `2021-07-28` is no longer actively maintained. Record the disagreement. Implement against the v3 reference and confirm with a live call before Phase 6. Do not invent a reconciliation.

### Rate limits

The FAQ describes a burst of 100 requests per 10 seconds and 200,000 requests per day, per marketplace app, per Location or Company. Response headers named there:

- `X-RateLimit-Limit-Daily`
- `X-RateLimit-Daily-Remaining`
- `X-RateLimit-Interval-Milliseconds`
- `X-RateLimit-Max`
- `X-RateLimit-Remaining`

### Install and contacts

The INSTALL webhook payload includes `locationId`, `companyId`, `isWhitelabelCompany`, and `whitelabelDetails.domain`. A white-label installation URL exists. It is not verified that BrandWorx's agency at `app.brandworx.ai` can install a private marketplace app.

Contact upsert: `POST /contacts/upsert` with `locationId` required. Duplicate behavior follows the location's "Allow Duplicate Contact" setting. The `tags` field replaces the contact's tags; adding or removing one tag uses the tag endpoints. `customFields` is supported. The response includes a `new` boolean.

A workflow-trigger API was not verified. Do not invent a trigger endpoint. Contact tags and custom fields are the documented surface.

### Webhooks

`X-GHL-Signature` (Ed25519) is the current signature. The legacy `X-WH-Signature` (RSA-SHA256) deprecation date is published as September 1, 2026, which is already past relative to October 23, 2026. The guide still describes a fallback. Prefer Ed25519.

Verify the raw body bytes. The guide's sample re-stringifies `req.body` after `JSON.parse`, which can change the bytes. Treat that as a hazard and confirm against a live webhook before writing a verifier. The verifier is Phase 6 and is not in this repository.

The Ed25519 public key published in the webhook guide is a verification key, not a secret:

`MCowBQYDK2VwAyEAi2HR1srL4o18O8BRa7gVJY7G7bupbN3H9AwJrHCDiOg=`

Non-2xx responses are retried up to 12 times excluding the original attempt, with exponential backoff and jitter. The circuit breaker is described as evaluating about every 3 days, and only when more than 10,000 webhooks were sent and the success rate is under 90%.

### Proposal

Marketplace OAuth app, distribution starting Private, target Sub-account, location-level tokens. Waiting on product-owner approval (open question 7).

## Email

Postmark is the proposed transactional provider. Laravel 12's mail documentation describes the Postmark transport and `POSTMARK_API_KEY`. The package is not installed. Default mailer is `log`. See [`DECISIONS.md`](DECISIONS.md).
