# Integrations

Research for Stripe Connect and GoHighLevel, retrieved October 23, 2026 from the official docs linked below. No live HighLevel call was made for this research. Do not copy sample client secrets from HighLevel's OAuth examples into this repository.

Payment state, when it exists, will come only from verified Stripe webhooks. A browser redirect is not payment proof. A GoHighLevel outage must not change bookings, payments, or inventory (spec Section 1.3 and D10). The outbox stores events and refuses a different workspace. It does not call HighLevel. D12 links each workspace to one BrandWorx sub-account with a Private Integration Token. That link is what sends booking and payment contact updates.

A workspace can also register its own HTTPS webhook endpoints. Those deliveries are signed and logged. They are not sent to HighLevel, and they do not choose an auth model for GoHighLevel.

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

### Decision

Michael decided on October 24, 2026 (D12) that ReserveWorx will not publish a Marketplace app and will not use OAuth. A platform administrator links one workspace to one BrandWorx-managed sub-account. The stored fields are the sub-account Location ID and a Private Integration Token. The token uses Laravel's encrypted cast. API responses, audit properties, workspace exports, sync logs, and exception messages do not include it.

`GET`, `PUT`, and `DELETE /api/v1/platform/tenants/{tenant}/ghl` read, replace, and remove that link. `POST .../ghl/test` calls `GET /locations/{locationId}`. `GET .../ghl/logs` lists the latest attempts. These routes require a platform administrator. Tenant owner, manager, and staff receive 403. `integrations.view` and `integrations.manage` exist in the permission catalog and are not attached to those roles.

When a link exists, these changes queue `SyncBookingToGoHighLevel`. A booking is created. Its status changes to confirmed (`booking.confirmed`) or cancelled (`booking.cancelled`). Its operational status changes to checked in, returned, overdue, or completed (`booking.checked_in`, `booking.returned`, `booking.overdue`, `booking.completed`). Any other watched detail changes, including operational status moving to in progress (`booking.changed`). A payment is created or updated with status paid (`payment.succeeded`) or failed (`payment.failed`). The checkout failure hook also queues `payment.failed` once for that session and leaves the payment row pending, so the hook and a status change do not both run for the same failure. A field that has its own event is not also sent as `booking.changed`. Other watched fields on that same save still queue one `booking.changed`. Cancelling queues only `booking.cancelled`, even when other fields change in that save. `booking.completed` is the post-activity tag a workflow can use for a review request or a repeat booking. The contact tags are `reserveworx` and `reserveworx-` plus the event name, with each dot replaced by a hyphen. Check-in is `reserveworx-booking-checked_in`. Completed is `reserveworx-booking-completed`. The first send for a customer calls `POST /contacts/upsert` with `locationId`, email and/or phone, `source: ReserveWorx`, and `createNewIfDuplicateAllowed: false`. It stores the returned contact id on `ghl_mappings` for that workspace, customer, and location. A later send, including a booking message, calls `PUT /contacts/{contactId}` for that stored id and does not upsert again. A 404, or a contact that comes back for a different location, drops the stored id and upserts once more. It does not send `tags` on upsert or update, because tags there replace the contact's tags. It then calls `POST /contacts/{id}/tags` and `POST /contacts/{id}/notes`. The note includes the short booking reference, the service, status, payment status, location-local start, party size, source `ReserveWorx`, attribution (`direct`, `third_party`, or `unrecorded` from the pricing snapshot channel), consent (`feedback allowed` or `feedback opted out`), and a `reserveworx:{kind}:{bookingId}:{paymentId}` marker. No customer email or phone means the attempt is skipped. No link means nothing is queued. A workspace reads only its own mapping. The customer export leaves the row out, and anonymizing the customer deletes it.

Requests use `https://services.leadconnectorhq.com`, `Authorization: Bearer`, and `Version: 2021-07-28` unless `GHL_BASE_URL` or `GHL_VERSION` is set. A non-2xx response throws `GhlRequestFailed` with the status and the call kind. The message does not include the response body. The job allows 5 tries. Backoff is 60, 300, 900, 3600, then 10800 seconds. Each attempt is a `ghl_sync_logs` row for that workspace. The production queue is `database`, so the job row is not visible to the worker until the booking transaction commits, and a GoHighLevel failure stays in the worker. The generic outbox still does not call HighLevel. `webhook_delivery_attempts` is the tenant endpoint delivery log. It requires an endpoint, stores response bodies, and does not call GoHighLevel, so these attempts stay on `ghl_sync_logs`.

### Integration logs

`integration_logs` keeps one row each time an outbound try happens: an outbox retry, a webhook delivery, a GoHighLevel contact sync, and a GoHighLevel message. The diagnostics page at `/admin/integrations` and `GET /api/v1/integrations/outbox` list the latest 20 for the current workspace. The list shows the channel, kind, try, status, HTTP status, and a short error. It does not show the payload, the response body, the webhook secret, or the private integration token. A member of another workspace is refused. The detailed webhook and GoHighLevel tables are unchanged.

### Booking messages

Spec Section 10 sends confirmations, receipts, reminders, cancellations, a new time, a failed payment, refunds (with the booking-fee refund itemized), overdue rentals, and a waitlist opening through GoHighLevel when the workspace is linked. A new time, a failed payment, and a waitlist opening use the same dedupe key as the notification hook. The message names the visit. A waitlist opening says the booking is held. It does not calculate a price, a tax, a booking fee, or a refund. The platform workspace page at `/platform/tenants/{id}` is where a platform administrator types the Location ID and Private Integration Token. The same platform API still accepts them. The token is cleared from the Livewire snapshot before the response and is not written to the audit row. Tenant staff cannot open that page.

`SendGhlBookingMessage` is queued on the database connection. It allows 5 tries, with backoff of 60, 300, 900, 3600, and 10800 seconds, for any response that is not a rate limit. A 429 waits for `Retry-After` when that header is present, clamped from 1 second through 10800 seconds. When it is missing and `X-RateLimit-Remaining` is 0, the wait is `X-RateLimit-Interval-Milliseconds`. Otherwise the wait is that same backoff. The wait is a new database job and does not use one of the five failure tries. After 8 waits the next 429 is stored as try 5 and the automatic queue stops. The job uses the stored contact for that workspace and location, upserting only when none is stored, then calls `POST /conversations/messages`. Email is used when the customer has an email. SMS is used when they have only a phone. The documented `status` value sent is `pending` (open question 28). The Version header is the same value contact sync sends. Each attempt updates `ghl_message_logs` with the status, HTTP status, and a short error. The row does not store the message body, the response body, or the token. A second queue for the same booking event hits the unique `(tenant_id, dedupe_key)` and does not send again.

No link leaves the existing email path alone. A linked confirmation or reminder is not also emailed. A linked receipt is not emailed either. When the workspace is not linked, `integrations:dispatch` emails the stored receipt for `payment.completed`. That email copies the amounts already on the booking. It does not change the price, the payment, the tax, the booking fee, or a refund. Turning the matching transactional message off records a skip and does not send. A GoHighLevel failure stays in the worker. The generic outbox still does not call HighLevel.

A contact sync or a customer message that has failed on its fifth try can be queued once more. The owner or manager uses **Try again** on `/admin/integrations`, or `POST /api/v1/integrations/ghl-syncs/{id}/retry` and `POST /api/v1/integrations/ghl-messages/{id}/retry`. `settings.update` is required. Staff with only `settings.view` can see the row and cannot send it. A try below five, a message that is already queued, a sync that already has a later try, and a workspace that is not linked are refused. The contact-sync retry writes one skipped `ghl_sync_logs` row, `Retry queued.`, so a second request does not dispatch again before the worker runs. The booking, its price, its payments, and its refunds are not changed. The response and the audit row do not include the private integration token.

The send-message schema was read on October 26, 2026 from https://marketplace.gohighlevel.com/docs/ghl/conversations/send-a-new-message/index.html. Required body fields there are `type`, `contactId`, and `status`. `type` is `Email` or `SMS`.

The rest of this section is the research that led to the earlier OAuth proposal. It is not the implementation.

Two supported auth types are documented: a Private Integration Token, and OAuth 2.0. v1 API keys are end of life. Do not build on them.

Sources used:

- https://marketplace.gohighlevel.com/docs/oauth/GettingStarted
- https://marketplace.gohighlevel.com/docs/Authorization/PrivateIntegrationsToken
- https://marketplace.gohighlevel.com/docs/oauth/FAQ
- https://marketplace.gohighlevel.com/docs/webhook/WebhookIntegrationGuide
- HighLevel API v3 reference for contacts upsert and location tokens (marketplace docs, Version `v3`, released June 11, 2026)

### Private Integration Token

A PIT is a static scoped token generated in the UI. It does not refresh. Official guidance describes it for internal use, when webhooks are not needed, and for one sub-account at a time. Requests send `Authorization: Bearer` and a `Version` header. That is a poor fit for one marketplace app installed into many tenant locations. D12 uses it anyway: one token per BrandWorx sub-account, entered by a platform administrator, not installed by tenant staff.

### OAuth

Authorization-code token exchange is `POST https://services.leadconnectorhq.com/oauth/token`. Documented access-token lifetime is about 24 hours (`expires_in` 86399 or 86400). The refresh token is valid for about a year or until it is used, and then it rotates. User types are Company (agency) and Location.

Location tokens: an older guide shows `POST /oauth/locationToken`. The v3 reference shows `POST /oauth/location-token`. Both spellings were present on October 23, 2026. Do not pick one in code until a live call confirms which path the chosen app version accepts.

Version header: examples on the OAuth pages still send `Version: 2021-07-28`. The versioning page says the current version is `v3`, released June 11, 2026, supported until a date that was still TBD, and that `2021-07-28` is no longer actively maintained. The sub-account sync sends `2021-07-28`. `GHL_VERSION` overrides that header. Open question 20 records that a live call has not confirmed the value.

### Rate limits

The FAQ describes a burst of 100 requests per 10 seconds and 200,000 requests per day, per marketplace app, per Location or Company. Response headers named there:

- `X-RateLimit-Limit-Daily`
- `X-RateLimit-Daily-Remaining`
- `X-RateLimit-Interval-Milliseconds`
- `X-RateLimit-Max`
- `X-RateLimit-Remaining`

Contact sync and booking messages honor those headers. `Retry-After` is the HTTP wait. It is a number of seconds or an HTTP date. A missing `Retry-After` with `X-RateLimit-Remaining: 0` uses `X-RateLimit-Interval-Milliseconds`. A daily remaining count of 0 does not invent a reset time. The wait is clamped to 1–10800 seconds and queued again. It does not count as one of the five failure tries. After 8 waits the row is stored as try 5 so an owner can send it again. The booking, its price, and its payments are not changed.

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

Superseded by D12. The earlier proposal was a Marketplace OAuth app, distribution starting Private, target Sub-account, location-level tokens. That app is not being built.

## Email

Postmark is the proposed transactional provider. Laravel 12's mail documentation describes the Postmark transport and `POSTMARK_API_KEY`. The package is not installed. Default mailer is `log`. See [`DECISIONS.md`](DECISIONS.md).
