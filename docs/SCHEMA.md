# Schema

Spec Section 13 lists the entities. This document says which of them exist in MySQL today. Timezone belongs on `locations`. Every timestamp that is stored is UTC.

## Migrated now

```mermaid
erDiagram
  users ||--o{ memberships : has
  users ||--o| platform_administrators : "may be"
  users ||--o{ sessions : has
  users ||--o{ personal_access_tokens : has
  tenants ||--|| tenant_settings : has
  tenants ||--o{ domains : has
  tenants ||--o{ memberships : has
  tenants ||--o{ audit_logs : "may have"
  roles ||--o{ memberships : grants
  roles ||--o{ role_permission : has
  permissions ||--o{ role_permission : has
  users ||--o{ audit_logs : "may act"

  users {
    ulid id PK
    string email UK
    string password
  }
  tenants {
    ulid id PK
    string slug UK
    string status
  }
  tenant_settings {
    ulid id PK
    ulid tenant_id FK
    smallint hold_minutes
    smallint ledger_bucket_minutes
    string fee_payer
    smallint direct_fee_bps
    smallint third_party_fee_bps
    char currency
  }
  domains {
    ulid id PK
    ulid tenant_id FK
    string hostname UK
    timestamp verified_at
  }
  memberships {
    ulid id PK
    ulid tenant_id FK
    ulid user_id FK
    ulid role_id FK
    string status
  }
  roles {
    ulid id PK
    string slug UK
  }
  permissions {
    ulid id PK
    string slug UK
  }
  platform_administrators {
    ulid id PK
    ulid user_id FK
  }
  audit_logs {
    ulid id PK
    ulid tenant_id FK "nullable"
    string action
    json properties
  }
```

`users.id` is a ULID. `sessions.user_id` and Sanctum's `personal_access_tokens.tokenable` use ULIDs as well. `audit_logs.tenant_id` is nullable because platform actions are not tenant-owned. Roles and permissions are system rows, not per tenant.

`tenant_settings` stores fee rates and payer, hold length, ledger bucket, currency, and `pay_at_pickup` (default false). Currency defaults to `USD` and is not writable by a tenant until launch markets are decided. Fee rates are writable only by a platform administrator. The fee calculation engine is not in the database. `pay_at_pickup` is changed by a member with `settings.update`. The demo shop seeder sets it for Harbor Demo and Inland Demo only.

Foundation migration: `database/migrations/2026_10_23_170000_create_foundation_tables.php`. Laravel cache, jobs, and sessions tables are also migrated.

## Inventory resources

`locations.timezone` is the IANA zone for that place. Tenants do not have a timezone. `assets` are individually tracked and `pools.quantity` is the live capacity. Buffers are minutes on the asset or pool. Status is `active`, `blocked`, or `maintenance`.

`availability_schedules` belong to a location, asset, or pool (`owner_type`, `owner_id`). `weekday` is Carbon's `0` Sunday through `6` Saturday. `blackouts.location_id` null covers the whole tenant. `services.kind` is `rental` or `tour`. `services.min_participants` and `services.max_participants` are optional. `services.waitlist_enabled` allows a refused hold to be stored as a waitlist entry. `service_durations` name a rental length (`minutes` or `hours`) and store `duration_minutes`. `resource_requirements` point at one asset or pool. `assets.is_guide` marks a bookable guide. `assets.turnaround_minutes` and `pools.turnaround_minutes` are the gap after a rental. `departures` are scheduled tour times, with `private` and optional `seats`.

## Bookings and the capacity ledger

`customers`, `bookings`, `booking_line_items`, `allocations`, `holds`, and `capacity_ledger` are migrated in `database/migrations/2026_10_23_193000_create_booking_tables.php`. `POST /api/v1/bookings` stores `customer_phone` on the customer. A later booking with the same email fills `phone` only when it is still empty. Booking JSON includes `customer_id` and `customer`.

`participants` is migrated in `database/migrations/2026_10_24_041500_create_participants_table.php`. It stores one named person on a booking (`tenant_id`, `booking_id`, `name`). `POST /api/v1/bookings/{booking}/participants` adds a name. The count of those rows is a floor: `POST /api/v1/bookings/{booking}/party-size` will not set the party size below it. A name is not added once the party is already full of names. Booking JSON includes `named_participant_count`. A response that loaded the names also includes `named_participants`.

A booking stores the customer interval in UTC, party size, status, payment status, and operational status. `departure_id` is set when the booking is for a tour departure. `guide_user_id` is the staff user guiding that booking. `created_by` is the member who placed the hold, when the request was authenticated. Idempotency is a unique `(tenant_id, idempotency_key)` plus `payload_hash`. `waitlist_entries` store a party that asked to wait. `booking_id` is set when that entry is promoted. Their idempotency key is unique per tenant and nullable. MySQL treats null keys as distinct, so bookings without a key do not collide. `allocations` and `holds` store the buffered interval, including turnaround. A hold counts only while `expires_at` is still in the future. `rental_checkouts` record an unreturned rental; `due_at` is the customer end, and `returned_at` null means the checkout still extends the block. `notification_hooks` record queued events such as `waitlist.promoted`. `capacity_ledger` is unique on `(tenant_id, pool_id, bucket_start_utc)`. Its counters are rewritten after a successful write and are not the admission check.

## Disclosure versions

`disclosure_versions` is migrated in `database/migrations/2026_10_23_200000_create_disclosure_versions_table.php`. It is tenant-owned and uses `BelongsToTenant`. Columns: `kind` (`booking_fee_refund_terms`), `version` (unique per tenant and kind), `fee_payer`, `rules_version`, `body` (one sentence per line), `content_hash`, and `published_at`. Rows are immutable: the model refuses updates and deletes. `pricing_snapshots.disclosure_version_id` records the version the customer saw.

## Pricing and payments

`price_rules`, `promo_codes`, `pricing_snapshots`, `stripe_accounts`, `payments`, and `platform_fees` are tenant-owned. `webhook_events` is not: Stripe calls the platform host before a tenant is known, and `stripe_event_id` is globally unique. A pricing snapshot is unique per booking. A Stripe account is unique per tenant and per `stripe_account_id`. A checkout session id is unique and nullable, so offline payments do not collide. `payments.idempotency_key` is unique per tenant when set, with `payload_hash` covering the booking and, for checkout, the success and cancel URLs. A repeated key with the same hash returns the original payment. A repeated key with a different hash is rejected. Webhook payments leave both columns null. A repeated checkout does not open a second Stripe session; the session URL is returned only on the first call. `stripe_fee_id` is unique and nullable until `application_fee.created` arrives. `card_payments_status` starts at `pending` and must be `active` before online checkout.

## Booking widget tokens

`widget_tokens` stores the SHA-256 hash of an embed token. The plain token is returned once when a member with `bookings.manage` creates it. `revoked_at` or a past `expires_at` makes the public booking pages 404. The token is not a cookie and is not a Stripe secret. `tenant_settings.guest_cancel_before_minutes` is null until the workspace turns guest cancellation on. `notification_hooks.dedupe_key` is unique when set, so a repeated guest cancel does not queue a second hook.

## Operations

`maintenance_windows` name one asset or one pool and a half-open interval. Creating one sets `bookings.attention_reason` on future held and confirmed bookings whose allocations overlap that resource. It does not cancel them. `GET /api/v1/maintenance-windows` lists them. `maintenance_records` are inspections of one asset: `inspected_at` and a `summary`. `GET` and `POST /api/v1/assets/{asset}/maintenance-records` read and write them. They do not change the asset status and they do not cancel bookings. `incidents` store a short summary and `occurred_at`. `GET /api/v1/bookings/{booking}/incidents` lists the incidents for that booking. `waiver_versions` are unique on `(tenant_id, name, version)` and store `content_hash`. `waiver_signatures` copy that hash and store IP, user agent, and a guardian name when the participant is a minor. `GET /api/v1/bookings/{booking}/waivers/{signature}` returns a PDF of that record and the template text. It does not decide how long the signature is kept. `tenant_settings.late_fee_per_minute_cents` is null until set. `rental_checkouts.late_fee_cents` is the stored whole-minute amount and is not a Stripe charge.

## Reports

`GET /api/v1/reports/today` and `GET /api/v1/reports/today/export` read live rows. `GET /admin` redirects to `/staff`. A booking counts on the calendar date of `starts_at` in its location timezone. Gross, tax, and the booking fee on that date come from those bookings' pricing snapshots. Card payments, offline payments, and customer refunds count on the day the row was recorded, in the report timezone. Open and overdue checkouts are current, not limited to that date. The net booking-fee total on this report is all time. That live read does not compute utilization, conversion, or revenue per resource-hour.

`report_summaries` stores one row per location per local date. `reports:build-summaries` rewrites yesterday and today for each active or onboarding workspace, using that location's timezone. `GET /api/v1/reports/summaries` reads an inclusive `from`/`to` window and does not rebuild the rows. Available minutes are the location's open minutes that day times active assets plus active pool quantity. A location with no hours is open for the whole local day, which is 23 or 25 hours when daylight saving starts or ends. A blackout, or a weekday the hours do not cover, has no available minutes. Overlapping hours are counted once. Allocated minutes are the overlap of allocations with those open intervals, times quantity. Utilization and conversion are basis points, rounded half-up. Conversion uses bookings created that local day; confirmed and no-show bookings are the numerator. Average order value uses `customer_total_cents` when the snapshot has it, otherwise the service total. Revenue per resource-hour uses the gross service cents of confirmed and no-show bookings that start that day, divided by available resource-hours. A day with no available minutes stores empty utilization and empty revenue per hour. `GET /api/v1/reports/revenue` reads confirmed and no-show bookings in an inclusive `from`/`to` window of at most 366 days and groups `services_cents`, or `basis_cents` when that is missing, by service and by the location-local date of `starts_at`. Taxes, the booking fee, and the customer total are not included. Held and cancelled bookings are not included. The sum for one location on one date is the same figure `report_summaries.gross_cents` stores. The read does not write a summary row. `GET /api/v1/reports/customers` uses the same window and the same confirmed and no-show bookings. A customer is new on the location and local start date of their earliest counted booking, ordered by `starts_at` and then by id. A later counted booking is a repeat. A booking with no customer is anonymous and is not a customer. Held and cancelled bookings are left out, including as earlier history. Attribution is the pricing snapshot `channel` when it is `direct` or `third_party`, and `unrecorded` otherwise. The read does not write a summary row. `GET /api/v1/reports/summaries/export`, `GET /api/v1/reports/revenue/export`, and `GET /api/v1/reports/customers/export` return those same rows as CSV. An empty utilization, conversion, average order, or revenue-per-hour cell stays blank. The customer file marks each line `customer` or `attribution`. These reads do not write a summary row. `GET /api/v1/platform/fee-reconciliation` reads stored fee rows for a required UTC window and compares them with Stripe. It does not add a summary row.

## Integration outbox

`integration_outbox` queues a GoHighLevel event for the current workspace only. `dedupe_key` is unique per tenant when set. `attempts` and `available_at` are the backoff. `status` stays `queued` until five attempts, then `held`. Nothing in this table is sent to HighLevel. `notification_hooks.delivery_status` stays `queued` until `integrations:dispatch` marks it `skipped` with `delivery_error` because no email provider is configured. Kinds written by the booking engine are `booking.confirmed`, `booking.cancelled` (staff and API cancel; guest self-service stays `booking.guest_cancelled`), `booking.checked_out`, `booking.returned`, `rental.overdue`, `payment.completed`, and `payment.failed`. `payment.completed` is written once per payment row when an offline payment is recorded or a paid checkout webhook is fulfilled. `payment.failed` is written for `checkout.session.async_payment_failed` and does not confirm, cancel, or change the payment row. It is not written when that checkout session is already `paid`, so a failure that arrives after the paid event leaves the booking confirmed. An unpaid `checkout.session.completed` does not confirm the booking. A later `checkout.session.async_payment_succeeded` with `payment_status` `paid` does. `rentals:flag-overdue` writes `rental.overdue` once per open checkout whose `due_at` is in the past. `GET /api/v1/notification-hooks` lists the latest hooks for the current workspace. No mail is sent.

## Refunds

A pricing snapshot may store `charged_basis_cents`, the subtotal the booking fee was charged on. It stays put when `basis_cents` is lowered by a price-decrease refund, and it grows when a later price increase is paid. Refunds use it as the proportional denominator when it is present. `refunds` stores one Section 7.7 outcome: the reason, the customer amount, the application fee amount, the subtotal and tax that were refunded, and the Stripe refund ids when a call was made. `idempotency_key` is unique per tenant when set. The row is written only after Stripe accepts the call, or immediately when the reason refunds nothing. `platform_fee_refunds` stores a platform administrator's fee refund, separate from those reason codes: the fee, the amount, the free-text reason, the rule `platform_manual`, the actor, and the Stripe fee-refund id. Its `idempotency_key` is unique per tenant when set. The row is written only after Stripe accepts the call. It does not refund the customer. `GET /api/v1/fee-ledger` reads these rows for the current workspace, grouped by booking. A Section 7.7 fee refund is shown on the booking's earliest platform fee. A manual refund is shown on the fee it names. Charged and refunded totals still come from `platform_fees`.

## Tenant data exports

`tenant_data_exports` records a private JSON file for one workspace. `path` is on the local disk, which is not public. `row_counts` is the number of rows written per table. Termination is refused while no completed file for that tenant is still on disk. The file omits passwords. It does not delete the workspace.

## Planned, not migrated

These Section 13 tables are design-only. Do not infer columns beyond the spec and [`ALLOCATION.md`](ALLOCATION.md):

- service_variants
- rental_returns beyond `rental_checkouts`
- service-level tax rows beyond the snapshot's tax basis points, and discount tables beyond `promo_codes`
- a waiver retention job. Signed waivers can be downloaded as PDF. How long they are kept is still open
- a mail transport and delivery webhooks
- ghl_connections, ghl_mappings, integration_logs, and a live HighLevel client

Tenant-owned planned tables get `tenant_id`, foreign keys, and the indexes their queries need. Allocation overlap queries and the ledger unique key are the hot ones. Idempotency for bookings and payments is a unique `(tenant_id, idempotency_key)` plus a payload hash.

## Tenant scope

Models that use `BelongsToTenant` get a global scope. With no current tenant the scope is `whereRaw('0 = 1')` unless platform code calls `withoutGlobalScope`. Creating a tenant-owned row without a tenant id in the attributes and without a current tenant throws. An explicit null `tenant_id` is preserved for `audit_logs`.
