# Schema

Spec Section 13 lists the entities. This document says which of them exist in MySQL today. Timezone belongs on `locations`, which is not migrated yet. Every timestamp that is stored is UTC.

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

## Planned, not migrated

These Section 13 tables are design-only. Do not infer columns beyond the spec and [`ALLOCATION.md`](ALLOCATION.md):

- customers
- services, service_variants, resource_requirements
- capacity_ledger `(tenant_id, pool_id, bucket_start_utc)`
- availability_schedules, blackouts
- tours, departures
- bookings, participants, booking_line_items
- allocations, holds
- rental_checkouts, rental_returns, maintenance_records, incidents
- pricing_snapshots, taxes, discounts
- payments, refunds, platform_fees, platform_fee_refunds
- stripe_accounts, webhook_events
- waiver_templates, waiver_signatures
- notifications
- ghl_connections, ghl_mappings, integration_outbox, integration_logs
- disclosure_versions

Tenant-owned planned tables get `tenant_id`, foreign keys, and the indexes their queries need. Allocation overlap queries and the ledger unique key are the hot ones. Idempotency for bookings and payments is a unique `(tenant_id, idempotency_key)` plus a payload hash.

## Tenant scope

Models that use `BelongsToTenant` get a global scope. With no current tenant the scope is `whereRaw('0 = 1')` unless platform code calls `withoutGlobalScope`. Creating a tenant-owned row without a tenant id in the attributes and without a current tenant throws. An explicit null `tenant_id` is preserved for `audit_logs`.
