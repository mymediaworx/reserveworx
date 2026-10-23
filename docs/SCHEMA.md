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

`tenant_settings` stores fee rates and payer, hold length, ledger bucket, and currency. Currency defaults to `USD` and is not writable by a tenant until launch markets are decided. Fee rates are writable only by a platform administrator. The fee calculation engine is not in the database.

Foundation migration: `database/migrations/2026_10_23_170000_create_foundation_tables.php`. Laravel cache, jobs, and sessions tables are also migrated.

## Inventory resources

`locations.timezone` is the IANA zone for that place. Tenants do not have a timezone. `assets` are individually tracked and `pools.quantity` is the live capacity. Buffers are minutes on the asset or pool. Status is `active`, `blocked`, or `maintenance`.

`availability_schedules` belong to a location, asset, or pool (`owner_type`, `owner_id`). `weekday` is Carbon's `0` Sunday through `6` Saturday. `blackouts.location_id` null covers the whole tenant. `services.kind` is `rental` or `tour`. `services.min_participants` and `services.max_participants` are optional. `services.waitlist_enabled` allows a refused hold to be stored as a waitlist entry. `service_durations` name a rental length (`minutes` or `hours`) and store `duration_minutes`. `resource_requirements` point at one asset or pool. `assets.is_guide` marks a bookable guide. `assets.turnaround_minutes` and `pools.turnaround_minutes` are the gap after a rental. `departures` are scheduled tour times, with `private` and optional `seats`.

## Bookings and the capacity ledger

`customers`, `bookings`, `booking_line_items`, `allocations`, `holds`, and `capacity_ledger` are migrated in `database/migrations/2026_10_23_193000_create_booking_tables.php`. `POST /api/v1/bookings` stores `customer_phone` on the customer. A later booking with the same email fills `phone` only when it is still empty. Booking JSON includes `customer_id` and `customer`.

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

`GET /api/v1/reports/today`, `GET /api/v1/reports/today/export`, and `GET /admin` read live rows. They do not add a summary table. A booking counts on the calendar date of `starts_at` in its location timezone. Gross, tax, and the booking fee on that date come from those bookings' pricing snapshots. Card payments, offline payments, and customer refunds count on the day the row was recorded, in the report timezone. Open and overdue checkouts are current, not limited to that date. The net booking-fee total on this report is all time.

## Integration outbox

`integration_outbox` queues a GoHighLevel event for the current workspace only. `dedupe_key` is unique per tenant when set. `attempts` and `available_at` are the backoff. `status` stays `queued` until five attempts, then `held`. Nothing in this table is sent to HighLevel. `notification_hooks.delivery_status` stays `queued` until `integrations:dispatch` marks it `skipped` with `delivery_error` because no email provider is configured. Kinds written by the booking engine are `booking.confirmed`, `booking.cancelled` (staff and API cancel; guest self-service stays `booking.guest_cancelled`), `booking.checked_out`, `booking.returned`, `rental.overdue`, `payment.completed`, and `payment.failed`. `payment.completed` is written once per payment row when an offline payment is recorded or a paid checkout webhook is fulfilled. `payment.failed` is written for `checkout.session.async_payment_failed` and does not confirm, cancel, or change the payment row. `rentals:flag-overdue` writes `rental.overdue` once per open checkout whose `due_at` is in the past. `GET /api/v1/notification-hooks` lists the latest hooks for the current workspace. No mail is sent.

## Refunds

`refunds` stores one Section 7.7 outcome: the reason, the customer amount, the application fee amount, the subtotal and tax that were refunded, and the Stripe refund ids when a call was made. `idempotency_key` is unique per tenant when set. The row is written only after Stripe accepts the call, or immediately when the reason refunds nothing.

## Tenant data exports

`tenant_data_exports` records a private JSON file for one workspace. `path` is on the local disk, which is not public. `row_counts` is the number of rows written per table. Termination is refused while no completed file for that tenant is still on disk. The file omits passwords. It does not delete the workspace.

## Planned, not migrated

These Section 13 tables are design-only. Do not infer columns beyond the spec and [`ALLOCATION.md`](ALLOCATION.md):

- service_variants
- participants
- rental_returns beyond `rental_checkouts`
- service-level tax rows beyond the snapshot's tax basis points, and discount tables beyond `promo_codes`
- a platform-only manual fee refund separate from the Section 7.7 reason codes
- a waiver retention job. Signed waivers can be downloaded as PDF. How long they are kept is still open
- a mail transport and delivery webhooks
- ghl_connections, ghl_mappings, integration_logs, and a live HighLevel client

Tenant-owned planned tables get `tenant_id`, foreign keys, and the indexes their queries need. Allocation overlap queries and the ledger unique key are the hot ones. Idempotency for bookings and payments is a unique `(tenant_id, idempotency_key)` plus a payload hash.

## Tenant scope

Models that use `BelongsToTenant` get a global scope. With no current tenant the scope is `whereRaw('0 = 1')` unless platform code calls `withoutGlobalScope`. Creating a tenant-owned row without a tenant id in the attributes and without a current tenant throws. An explicit null `tenant_id` is preserved for `audit_logs`.
