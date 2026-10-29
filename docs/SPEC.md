# ReserveWorx — Project Specification and Agent Operating Guide

**Product:** ReserveWorx, a BrandWorx product
**Type:** Multi-tenant booking, scheduling, rental, and operations SaaS for tour, activity, and rental operators
**Hosting:** SiteGround (plan and capabilities to be verified in Phase 1)
**CRM:** White-label GoHighLevel at https://app.brandworx.ai
**Payments:** Stripe Connect (Standard accounts, direct charges)
**Revenue model:** Per-transaction application fee only. No subscription.
**Product owner:** Michael (BrandWorx)
**Document version:** 1.2, October 29, 2026

---

## 0. How the Project Agent Uses This Document

You are the project manager and lead technical agent for ReserveWorx. This document is the source of truth for scope, architecture, and business rules. Start each task from `docs/HANDOFF.md` and open only the sections of this document the task needs.

### 0.1 Your responsibilities

1. Break each phase in Section 15 into GitHub milestones and issues. Each issue must have acceptance criteria that trace back to a section of this document.
2. Record each real decision (architecture, data, money, security, isolation, integrations, hosting) as its own file in `docs/decisions/`, with its date, the options considered, what was chosen, and who approved it. `docs/DECISIONS.md` is a short summary; do not append to it. Per-screen display choices go in the pull request description only.
3. Keep `docs/OPEN_QUESTIONS.md` up to date. Anything listed in Section 18, plus anything new you discover, stays open until the product owner answers it.
4. Before starting each phase, post a plan. At the end of each phase, post a status report covering what was built, which tests pass, what is blocked, and what needs a decision.
5. Keep this document current. When an approved decision changes a rule here, update the relevant section and bump the version.

### 0.2 Rules you must follow

- **Never invent capabilities.** Do not assume hosting features, Stripe or GHL API behavior, scopes, limits, credentials, or permissions. Verify each against current official documentation or the live environment, and cite the source in the decision log.
- **Do not make product-owner decisions.** Anything in Section 18, anything about money, pricing, legal disclosure, or data retention, and anything that changes tenant isolation goes to the product owner.
- **Never weaken the non-negotiables in Section 1.3** to hit a deadline.
- **Never put secrets in the repository,** in issues, in logs, or in chat. Use environment variables and the hosting secret store.
- **A frontend prototype is not a finished feature.** A feature is done only when its server-side logic, its tests, and its documentation are complete.

---

## 1. Objective

Build a production-ready, commercially viable multi-tenant SaaS that lets independent attraction and activity businesses take bookings, manage shared physical inventory, process payments, and run day-to-day operations from one application and one codebase. Target businesses include kayak, paddleboard, canoe, boat, and bicycle rentals, guided tours, and similar operators.

### 1.1 Primary objective

Reliable booking, scheduling, inventory allocation, payments, and operational management **without double booking resources across tours and rentals.**

### 1.2 Business model

BrandWorx earns revenue only through a per-transaction application fee on payments processed through Stripe. Tenants pay no subscription. BrandWorx pays no Stripe processing costs. See Section 7.

### 1.3 Non-negotiables

1. No double booking under any concurrency condition.
2. Complete tenant data isolation in every layer.
3. Payment state comes only from verified Stripe webhooks, never from browser redirects.
4. All prices, fees, availability, and taxes are calculated on the server.
5. Every fee is disclosed to the customer before payment, and every fee refund follows the published rules in Section 7.6.
6. A GHL outage must never affect bookings, payments, or inventory.

---

## 2. Hosting and Technology

### 2.1 Stack (subject to Phase 1 verification)

- Laravel (latest LTS-compatible version supported by the host), PHP, and MySQL 8 or MariaDB 10.6+
- A server-rendered admin interface plus an embeddable booking widget. The frontend approach must be confirmed in Phase 1 (for example, Blade with Livewire or Inertia with Vue). It must work without Node at runtime on the server.
- Git-based deployment to SiteGround, with documented steps for build, deploy, migration, and rollback

### 2.2 Hosting verification (Phase 1, first task)

The agent cannot inspect SiteGround directly. Ask the product owner for either SSH or Site Tools access, or the following details:

- The plan name (StartUp, GrowBig, GoGeek, or Cloud)
- Available PHP versions, `memory_limit`, `max_execution_time`, and the PHP extensions in use
- The MySQL or MariaDB version, plus confirmation of InnoDB, transactions, and `SELECT … FOR UPDATE` support
- The minimum cron interval
- Whether SSH and Git access are available, and whether Composer can run on the server
- Process limits, and whether long-running processes are allowed
- Support for custom domains, wildcard subdomains, and SSL (including whether wildcard SSL is available)
- Outbound HTTPS access, for Stripe and GHL APIs
- Inbound webhook handling limits, such as WAF or rate rules that could block Stripe or GHL webhooks

Record the findings in `docs/HOSTING.md`, along with a recommendation. If the plan cannot meet the requirements, propose the simplest upgrade path, such as a SiteGround Cloud plan or a small VPS. Do not migrate without approval.

### 2.3 Background processing on shared hosting

Assume there are **no persistent workers and no WebSockets** unless Phase 1 proves otherwise.

- Use the Laravel queue with the **database driver**, processed by `schedule:run` triggered from cron at the minimum interval the host allows (ideally every minute). Bound each run with `queue:work --stop-when-empty --max-time`.
- Every time-dependent process must tolerate up to one cron interval of delay. This covers hold expiry, webhook processing, GHL sync, notifications, and summary-table builds.
- Hold expiry must also be enforced **at read time**: an expired hold never counts against availability, even before the cleanup job runs.
- Calendar refresh uses polling rather than WebSockets.

### 2.4 Time handling

- Store all timestamps in UTC.
- Timezones belong to each **location**, not to the tenant.
- All schedule calculations, including operating hours, departures, and DST transitions, use the location's timezone.
- Tests must cover the spring-forward and fall-back transitions, and multi-day rentals that span them.

---

## 3. Multi-Tenant Architecture

- One application and one shared database with a `tenant_id` on every tenant-owned table.
- A central tenant registry with immutable ULID or UUID identifiers.
- Tenant context is resolved from the verified domain or subdomain **and** the authenticated user's membership. A tenant ID supplied by the client is never trusted on its own.
- Isolation is enforced with a global scope on all tenant models, **plus** explicit authorization policies, **plus** tests that attempt cross-tenant access through every API endpoint. The global scope alone is not sufficient.
- Queued jobs, webhooks, reports, exports, and scheduled tasks must each carry and assert tenant context.
- Each tenant has its own branding, users, roles, locations, services, resources, pricing, policies, customers, bookings, waivers, Stripe connected account, GHL location connection, notification settings, and reports. Each tenant can optionally have its own custom domain or subdomain.
- A platform administrator role has audited access to platform operations: tenant onboarding, suspension, data export, termination, and fee configuration.
- Tenant lifecycle states: onboarding → active → suspended → terminated, with data export available before termination.
- A new business can sign up at `app.reserveworx.com/signup` (Michael, October 24, 2026). The workspace is created in onboarding with the standard fee defaults. The owner verifies their email before that workspace can move to active from its checklist. Platform administrators still see it in the tenant list and can change its status. An owner who is already signed in can still add another business.

---

## 4. Inventory Allocation Engine (Critical)

### 4.1 Resource model

The system supports two kinds of resources:

- **Individually tracked assets**, such as a specific boat, kayak, or bike, or a guide. Each has a unique identifier, category, location, capacity, status, maintenance records, and buffers.
- **Pooled inventory**, such as 20 single kayaks, 40 life jackets, or tour seats. Each pool has a total quantity, a location, and buffers.

Services declare their **resource requirements**, for example: "1 tandem kayak + 2 life jackets + 2 paddles per party of 2," or "1 guide per 8 participants." All services draw on the same underlying resources. Tours and rentals never keep separate inventory counts.

### 4.2 Time and buffers

- Intervals are **half-open**: `[start, end)`.
- An allocation occupies the interval `[start − prep_buffer, end + return_buffer)`, using the buffers configured on each resource or pool.
- A resource is available only when all of these hold:
  - it is eligible for the service;
  - it is active, not blocked, and not under maintenance;
  - the location is open and the date is not a blackout date;
  - no conflicting allocation, active rental, or **unexpired** hold overlaps its buffered interval.

### 4.3 Locking strategy

The agent must document the design in `docs/ALLOCATION.md` before writing code. The approved approach:

- **Pools:** use a capacity ledger keyed by `(tenant_id, pool_id, time_bucket)`. The bucket size is configurable per tenant, with a default of 15 minutes. An allocation locks every bucket row it touches with `SELECT … FOR UPDATE`, in a **consistent order** (pool_id, then bucket ascending) to prevent deadlocks. It then rechecks `allocated + held + requested ≤ capacity` and writes the result. Bucket rows are created lazily with insert-ignore before locking.
- **Individual assets:** lock the asset rows (ordered by ID) with `SELECT … FOR UPDATE`, then recheck for overlapping allocations inside the transaction.
- **Composite bookings** (several pools plus assets) take all their locks in one global order: pools first, then assets, each sorted by ID.
- On a deadlock, retry the transaction a bounded number of times with jitter. If it still fails, return a clean "no longer available" response.

If Phase 1 or Phase 3 finds a simpler mechanism that is equally proven, propose it in the decision log before switching.

### 4.4 Allocation transaction

Every allocation runs as one transaction:

1. Begin the transaction.
2. Resolve the eligible resources.
3. Acquire locks in the global order.
4. Recheck availability and capacity under the locks.
5. Create the booking, line items, and allocation (or hold) records.
6. Commit only if every constraint passes. Otherwise roll back.

Frontend availability checks and unlocked overlap-count queries are never authoritative.

### 4.5 Holds and checkout

- Starting checkout creates a hold, configurable per tenant with a default of 15 minutes. The hold reduces availability immediately.
- An expired hold never counts against availability (enforced at read time), and a cleanup job removes it.
- Checkout requests carry an idempotency key, so retrying never creates a duplicate booking.
- **A successful payment never revives an expired hold.** If payment succeeds after the hold has expired, the system attempts a fresh allocation. If that fails, the booking moves to `payment_exception` and the refund rules in Section 7.6 apply.

### 4.6 Rental extensions

An extension allocates the extra time atomically under the same locking rules. If there is a conflict, the extension is rejected and alternatives are offered (a shorter extension or a different asset). Any extension charge through Stripe follows the fee rules in Section 7.

### 4.7 Concurrency tests (required)

- These tests **must run on real MySQL or MariaDB**, matching the production version. They must never run on SQLite, which ignores row locks.
- Use separate database connections or processes to fire simultaneous requests at:
  - the last available individual asset;
  - the last N units of a pool;
  - a tour and a rental competing for the same kayaks;
  - an extension competing with a new booking.
- The tests must assert three things: no two conflicting requests both succeed, capacity never goes negative, and no orphaned allocations or holds remain after a failure.
- CI (GitHub Actions) runs these tests against a MySQL service container on every pull request.

---

## 5. Booking and Scheduling

- Rental lengths: hourly, half-day, full-day, and multi-day.
- Scheduled tours with multiple departures, private tours, and group bookings.
- Minimum and maximum participant counts. A tour below its minimum is flagged before departure for the operator to decide.
- Guide and equipment assignment, plus waitlists where configured.
- Staff-created bookings, customer modifications and cancellations, and conflict-aware rescheduling and reassignment. All of these revalidate inventory on the server.
- An admin calendar with day, week, month, and agenda views, filterable by service, resource, location, staff member, and status.
- Three status dimensions, each tracked separately:
  - **Booking status:** pending_payment, held, confirmed, cancelled, no_show, payment_exception
  - **Payment status:** unpaid, deposit_paid, paid, partially_refunded, refunded, disputed, offline_paid
  - **Operational status:** not_arrived, checked_in, in_progress, returned, completed, overdue

---

## 6. Customer Booking Experience

- A responsive, mobile-first widget that can be **embedded on a tenant's website** or hosted on the tenant's custom domain or subdomain.
- **Embedding:** Safari and other browsers block third-party cookies inside iframes. The embedded checkout must therefore use token-based checkout sessions (a signed, short-lived token per checkout) rather than cookie sessions. Stripe payment UI runs through Stripe's supported embedded components or Stripe Checkout.
- The flow: select a service, choose a date, time, and duration, enter group size and participant details, view live availability, choose equipment and add-ons, review pricing (including the booking fee, per Section 7.5), enter contact details, sign waivers, pay, and receive confirmation with a booking reference.
- Guest checkout plus optional customer accounts.
- When the requested slot is unavailable, show clear errors and suggest alternative times or resources.
- Meet the WCAG 2.1 AA accessibility standard for the customer-facing flow.

---

## 7. Pricing, Payments, and Platform Fees

### 7.1 Pricing engine

The pricing engine supports:

- Base prices, duration-based prices, and per-person prices
- Group pricing, and seasonal, peak, weekday, and weekend rates
- Deposits and remaining balances, add-ons, and equipment fees
- Discounts and promotional codes
- Taxes and mandatory fees
- Late-return fees, and cancellation and refund policies

Each booking stores a **pricing snapshot** containing every line item, the tax, the fee rate, the fee amount, the fee payer, and the version of the rules applied.

### 7.2 Stripe merchant model

- **Stripe Connect with Standard connected accounts** and **direct charges** on each tenant's account. Stripe sets processing pricing and collects it from the tenant. BrandWorx pays no Stripe processing fees and no per-account Connect fees.
- Tenant onboarding includes the Stripe Connect onboarding flow. Online payments are blocked until the connected account reports `charges_enabled`.
- A Connect webhook endpoint receives events from connected accounts. Each event is mapped to its tenant through the stored `stripe_account_id`, verified by signature, deduplicated by event ID, and processed idempotently. It must tolerate out-of-order delivery.
- The system never stores card numbers or CVCs, and never treats a browser redirect as proof of payment.
- **Verify all Stripe API details against current Stripe documentation** before implementation, and record the API version used.

### 7.3 Platform fee: when it applies

The application fee is charged **only on payments processed through Stripe.**

- Online customer checkout carries the fee.
- Staff-initiated Stripe payments carry the fee. This includes payment links, card-not-present payments entered by staff, and Stripe Terminal, if implemented.
- Payments recorded as offline (cash, check, external terminal, bank transfer) carry **no fee**. They are recorded with payment status `offline_paid` and payment method `offline`.

### 7.4 Platform fee: rates and calculation

| Channel | Default rate |
|---|---|
| Direct bookings (tenant widget, site, or staff-initiated Stripe payment) | **3%** |
| Third-party, reseller, or API bookings | **1%** |

- Rates are configurable per tenant by the platform administrator only. Each rate supports a percentage, an optional fixed amount, and an optional minimum. All are disabled except the percentage by default.
- **Fee basis:** the booking subtotal (services, add-ons, and mandatory operator fees, minus discounts). Taxes and the booking fee itself are excluded.
- Round half-up to the cent.
- **Full fee on first payment:** the full booking fee is collected on the first Stripe payment for a booking, deposits included. Later balance payments carry no additional fee.
- If a deposit would be smaller than or equal to the booking fee, raise the deposit to at least fee + $1.00 and show the adjusted amount to the customer. Stripe requires the application fee to be less than the charge.
- If a booking's price later increases (through a modification or added items) and the extra amount is paid through Stripe, the fee is calculated on the increase at the time of that payment.
- The calculated fee is passed as `application_fee_amount` on the PaymentIntent and recorded in the pricing snapshot.

### 7.5 Fee payer option (per tenant)

Each tenant chooses one of two modes in its settings:

- **Customer-paid (default):** the booking fee appears as a separate line item labeled **"Booking fee"** and is added to the customer's total. Stripe charge = subtotal + taxes + booking fee. Application fee = booking fee.
- **Operator-absorbed:** the customer sees no fee line. Stripe charge = subtotal + taxes. Application fee = booking fee, deducted from the tenant's proceeds.

Changing the mode applies only to bookings created after the change. Existing bookings keep their snapshot.

### 7.6 Disclosure and compliance requirements

These rules are designed to meet common "all-in pricing" and junk-fee rules, including California's SB 478 and the FTC's fee rules. **Before launch, the product owner must have an attorney confirm them for each state and country where tenants operate.** Track this as an open question.

1. **All-in price from the first display.** In customer-paid mode, every price shown to customers (service listings, the date and time picker, the cart, and checkout) is the total price including the booking fee. Taxes may be shown separately where the law allows.
2. **Itemize at checkout.** The checkout summary lists the subtotal, booking fee, taxes, and total.
3. **Use the label "Booking fee."** Never call it a "credit card fee," "surcharge," or "processing fee," and never describe it as a government charge.
4. **Disclose the refund terms before payment.** The checkout shows a plain-language summary of how the booking fee is treated on refunds, generated from the rules in Section 7.7.
5. **Receipts and confirmations** itemize the booking fee and show the refund terms.
6. **Refund receipts** itemize the booking fee amount that was refunded.
7. **Tax on the fee:** whether the booking fee is taxable varies by jurisdiction. Add a configurable per-location setting for this and flag it as an open question.
8. All disclosure text lives in versioned templates. Each booking records which disclosure version the customer saw.

### 7.7 Booking fee refund rules

Mechanics: a refund to the customer comes out of the tenant's connected account. Refunding the application fee returns that fee from BrandWorx to the tenant's connected account. So in customer-paid mode, the customer gets the booking fee back as part of the refund and the tenant is made whole by the application fee refund. In operator-absorbed mode, the application fee refund returns the fee to the tenant.

| Scenario | Fee refunded to tenant | Customer refund (customer-paid mode) |
|---|---|---|
| Operator cancels (including weather, safety, or a tour below its minimum) | **100%** | Full refund, including the booking fee |
| Platform or system fault (oversell exception, payment-after-hold-expiry with no inventory, duplicate charge, platform error) | **100%** | Full refund, including the booking fee |
| Customer cancels and receives a full refund under the tenant's policy | **100%** | Full refund, including the booking fee |
| Customer cancels and receives a partial refund under the tenant's policy | **Proportional** to the share of the subtotal refunded | Booking fee refunded in the same proportion |
| Customer cancels with no refund under the tenant's policy | None | None |
| Booking modified to a lower price (refund of the difference) | Proportional to the subtotal reduction | Proportional |
| Dispute or chargeback lost by the tenant | **100%** | (handled by the dispute process) |
| Dispute won by the tenant | None | None |
| Customer no-show | Follows the tenant's no-show policy, treated as a customer cancellation | Same |

Implementation:

- **Full refunds:** issue the refund with `refund_application_fee=true`.
- **Partial or proportional refunds:** issue the refund, then create an application fee refund for the calculated proportional amount. Never rely on Stripe defaults for partial cases.
- **Lost disputes:** listen for the dispute-closed webhook. If the dispute was lost, create the application fee refund automatically.
- Every fee refund records its reason code, the rule applied, the amount, and the actor (system or user), and is written to the audit log.
- Tenants see a fee ledger per booking showing each fee charged, each fee refunded, and the reason.
- The platform administrator can issue a manual fee refund with a mandatory reason. Manual refunds are audited and reported separately.

### 7.8 Reconciliation and reporting

- Listen for the `application_fee.created` and `application_fee.refunded` webhook events.
- Platform reports show gross fees, refunded fees, and net fee revenue, broken down by tenant, by channel, and by period, reconciled against Stripe.
- Tenant reports show fees paid and refunded on their bookings.

---

## 8. GoHighLevel Integration

- Each tenant connects its own GHL location (sub-account) under the white-label agency at app.brandworx.ai.
- **Phase 1 decision (Michael, October 24, 2026):** there is no public Marketplace app and no OAuth app. A platform administrator links each workspace to one BrandWorx sub-account with a Location ID and a Private Integration Token. See D12 and `docs/INTEGRATIONS.md`. Contact sync sends booking created, confirmed, changed, and cancelled; payment succeeded and payment failed; and check-in, return, overdue, and completed. Completed is the post-activity tag, so a workflow can ask for a review or offer another booking. A change that has its own tag is not also sent as booking changed. Cancelling still sends only booking cancelled. Reminders still go out as the messages in Section 10.
- Contact sync covers name, email, phone, booking reference, service, date and time, booking status, payment status, source and attribution, and consent preferences. A stable ID mapping prevents duplicate contacts.
- These events go to GHL through tags, custom fields, or supported workflow triggers:
  - booking initiated, confirmed, modified, or cancelled
  - payment completed or failed
  - reminders
  - check-in and return
  - overdue rentals
  - post-activity follow-up, review requests, and repeat-booking campaigns
- The GHL calendar is never the source of truth for inventory.
- **Reliability:** use an outbox table for all outbound sync. Include retries with backoff, rate-limit handling, deduplication, integration logs, and an admin diagnostics page. A GHL outage queues events and never blocks bookings.
- No tenant's data may ever be sent to another tenant's GHL location. This must be enforced in code and covered by tests.

---

## 9. Rental Operations and Maintenance

Staff workflows cover:

- lookup, check-in, assignment, and dispatch
- condition checks and returns
- late-return tracking and late fees
- extensions
- damage and incident reports
- maintenance scheduling and inspections
- temporary inventory blocks
- retiring and replacing resources
- archiving a location from staff settings. An owner or manager with `settings.update` opens Delete, sees how many upcoming held or confirmed bookings the location has and how many assets and pools it keeps, then types the location's name. Archiving sets `deleted_at` and leaves the row in place. It is refused while an upcoming held or confirmed booking exists, and while the location is the workspace's only one. Past bookings, payments, fees, and refunds stay and still point at the location. An archived location is left off the public booking page and staff location pickers. The audit action is `location.archived`

When a resource becomes unavailable, the system finds affected future bookings and flags them. It never cancels or reassigns automatically without validated alternatives and a recorded action.

---

## 10. Waivers and Communications

- Waiver templates are versioned. Each signature is tied to a participant, with timestamp, IP address, user agent, and a hash of the template version. Signed waivers can be exported as PDF.
- **Minors:** a minor participant's waiver must be signed by a parent or guardian, and the guardian is linked to the minor's record.
- **Retention:** signed waivers are kept for a configurable period after the activity date (the default is an open question). They survive tenant termination for that period under platform custody, and are exportable to the tenant.
- Electronic signatures must follow the federal ESIGN Act and UETA consent and record-keeping requirements. These must be reviewed by the attorney.
- **Notifications** cover confirmations, receipts, reminders, cancellations, refunds (including fee refund itemization), and overdue rentals. They are sent through GHL where supported, with a **transactional email provider** as a fallback (Postmark, Amazon SES, or Mailgun; choose one in Phase 1). Never use PHP `mail()`. Duplicate sends are suppressed, and delivery failures are recorded.

---

## 11. Reporting and Analytics

- Tenant dashboards cover:
  - today's bookings and departures
  - utilization, what is currently checked out, and overdue rentals
  - tour capacity and attendance
  - revenue by service and date
  - captured payments and refunds, and booking fees charged and refunded
  - conversion rate and average order value
  - cancellation and no-show rates
  - repeat customers and attribution
  - revenue per available resource-hour
- Reports clearly distinguish gross booking value, captured payments, offline payments, refunds, taxes, and booking fees.
- Heavy metrics are computed into **summary tables by scheduled jobs**, not run live on shared hosting.
- CSV exports are available, and tenant permissions are enforced on every report.
- The platform administrator has a fee revenue report (Section 7.8).

---

## 12. Security

- HTTPS everywhere, secure sessions, and Argon2id or bcrypt password hashing.
- Role-based access control.
- Server-side validation, plus protection against SQL injection, XSS, CSRF, and IDOR.
- Rate limiting on login, public signup, availability, and checkout endpoints.
- Encrypted storage for Stripe and GHL credentials, using Laravel encryption with the key stored outside the repository.
- Audit logs for administrative, inventory, financial, and fee actions.
- Automated database backups, with a documented and **tested** restore procedure.
- Dependency scanning using Dependabot and `composer audit` in CI.
- Data export, retention, and deletion procedures.
- Before launch, run cross-tenant access tests and a security review.

---

## 13. Data Model and API

The minimum entities are:

- tenants, tenant_settings, domains, memberships, users, roles, permissions
- locations (with timezone, and `deleted_at` when archived), customers
- services, service_variants, resource_requirements
- resource_categories, assets, pools, capacity_ledger
- availability_schedules, blackouts
- tours, departures
- bookings, participants, booking_line_items
- allocations, holds
- rental_checkouts, rental_returns, maintenance_records, incidents
- pricing_snapshots, taxes, discounts
- payments, refunds, **platform_fees**, **platform_fee_refunds**
- stripe_accounts, webhook_events
- waiver_templates, waiver_signatures
- notifications
- ghl_connections, ghl_mappings, integration_outbox, integration_logs
- disclosure_versions, audit_logs

Every tenant-owned table has `tenant_id`, foreign keys, the indexes needed for its hot queries (especially overlap queries on allocations), and unique constraints where business rules require them.

**API:** a versioned REST API (`/api/v1`) covering availability, holds, bookings, payments, rentals, and integrations. It must enforce authentication, authorization, and validation, and accept idempotency keys on all mutating payment and booking endpoints.

Deliverables for this section are the ERD and schema documentation in `docs/SCHEMA.md`.

---

## 14. Testing and Acceptance Criteria

Test types: unit, integration, concurrency (on real MySQL), security, and end-to-end.

Launch requires all of the following to pass:

1. Multiple tenants operate independently in one application.
2. Every endpoint passes the cross-tenant isolation tests.
3. There is no double booking under concurrent load (Section 4.7).
4. Tours and rentals share inventory correctly.
5. Holds expire safely, and expired holds never block availability.
6. Rental extensions never conflict.
7. Stripe payments and webhooks reconcile, including retries and out-of-order events.
8. **Booking fees calculate correctly** for each channel and payer mode, for deposits, and for modifications. Offline payments carry no fee.
9. **Every fee refund rule** in Section 7.7 produces the correct customer refund and application fee refund.
10. **Fee disclosures** appear on every price display, at checkout, and on receipts, in customer-paid mode.
11. GHL sync is correct, and outages are handled without data loss.
12. The system recovers from payment and integration failures.
13. Reports reconcile with Stripe.
14. Tenant domains and authentication are secure.
15. Backup, restore, and migrations all succeed.
16. The application runs within the verified SiteGround limits.

The application is not production-ready until criteria 2, 3, 7, 8, and 9 pass.

---

## 15. Phases and Milestones

Each phase becomes one GitHub milestone. Deliver working, tested functionality at the end of each phase.

1. **Discovery and architecture.** Verify the hosting. Write `HOSTING.md`, `ALLOCATION.md`, `SCHEMA.md`, and `INTEGRATIONS.md`. Choose the frontend approach and the email provider. Resolve the GHL auth model. Present the Section 18 decisions to the product owner.
2. **Foundation.** Repository and CI, authentication, roles, tenant registry and routing, settings, migrations, audit logging, and baseline security.
3. **Booking and inventory engine.** Resources, pools, availability, the capacity ledger, atomic allocation, holds, bookings, the calendar, and the concurrency test suite.
4. **Customer experience and payments.** The booking widget (with token-based embedding), the pricing engine, Stripe Connect onboarding, checkout, webhooks, **the platform fee engine, disclosures, refund rules, and fee reconciliation.**
5. **Operations.** Dispatch, returns, extensions, maintenance, incidents, waivers (including minors), notifications, and operational reports.
6. **GHL integration.** OAuth connection, contact sync, event triggers, the outbox, and diagnostics.
7. **Production readiness.** Full reporting, security testing, end-to-end tests, a backup and restore drill, performance checks on SiteGround, deployment documentation, the legal review sign-off, and a multi-tenant demo.

---

## 16. Repository Conventions (GitHub)

- **Branches:** `main` is always deployable. Work happens on `feature/<issue-number>-<slug>`. Changes merge through pull requests only.
- **Pull requests:** each must link its issue and include tests. CI must be green (lint, static analysis, unit tests, and MySQL concurrency tests). Any PR touching allocation, payments, fees, or tenant isolation requires review.
- **Required files:**
  - `README.md`
  - `docs/HANDOFF.md` (the agent's starting point), `docs/DECISIONS.md` with `docs/decisions/`, `docs/OPEN_QUESTIONS.md`, `docs/HOSTING.md`, `docs/ALLOCATION.md`, `docs/SCHEMA.md`, `docs/INTEGRATIONS.md`, `docs/FEES.md` (Section 7 as implemented), `docs/DEPLOY.md`, `docs/RUNBOOK.md`
  - `.env.example` (no real values)
- **Secrets:** use GitHub Actions secrets for CI and the server environment for production. Never commit them.
- **Versioning:** semantic versioning, with a CHANGELOG and tagged releases. A pull request adds `docs/changelog.d/<branch-or-slug>.md` and does not edit the Unreleased section of `docs/CHANGELOG.md`.
- **Deployment:** a documented script for SiteGround (pull, composer install, migrate, cache, and a cron check), with a rollback procedure.

---

## 17. Deliverables

- Architecture, hosting compatibility, allocation design, schema and ERD, integration, and fee documentation
- Complete source code, migrations, and seed and demo data (at least two demo tenants with different fee-payer modes)
- The Stripe Connect integration, including the platform fee engine
- The GHL integration
- Automated test suites, including the MySQL concurrency tests
- Installation, deployment, backup, recovery, and upgrade documentation
- Admin and staff user documentation
- A security and production-readiness report
- A multi-tenant demonstration

---

## 18. Decision Log and Open Questions

### 18.1 Decided by the product owner (October 23, 2026)

| # | Decision |
|---|---|
| D1 | Stripe Connect, Standard accounts, direct charges, Stripe-owned processing pricing |
| D2 | No subscription. Revenue comes from the application fee only. |
| D3 | Fee rates set at roughly half of FareHarbor's: 3% for direct bookings and 1% for third-party or API bookings |
| D4 | Each tenant chooses who pays the fee: customer-paid (default) or operator-absorbed |
| D5 | Fee disclosures must meet the applicable compliance requirements (Section 7.6) |
| D6 | The fee is refunded when appropriate, using transparent, published rules (Section 7.7) |
| D7 | The full fee is collected on the first Stripe payment, including deposits |
| D8 | The fee is charged only on payments processed through Stripe. Offline payments carry no fee. |
| D9 | Laravel, PHP, and MySQL or MariaDB on SiteGround, subject to verification |
| D10 | GHL is never the source of truth for inventory |
| D13 | A new business can sign up in public at `app.reserveworx.com/signup`. The workspace starts in onboarding with the standard fee defaults. Email verification is required before that workspace goes active from the checklist. Michael approved this on October 24, 2026 |

### 18.2 Open questions for the product owner

1. Which SiteGround plan is it, and how will the agent get access? (Phase 1 blocker)
2. Legal review: an attorney must confirm the fee disclosure wording, the all-in pricing approach, the taxability of the fee, the e-signature process, and the waiver approach for the launch jurisdictions.
3. How long should waivers be retained by default after the activity date?
4. Should there be a minimum booking fee per transaction (for example, $0.50 or $1.00) for low-priced items? The default is none.
5. Confirm the proportional fee refund rule for partial refunds (Section 7.7). The proposed default is in place.
6. Should staff-initiated Stripe payments for walk-up customers carry the full 3%? Under D8, they currently do.
7. GHL auth model: a Marketplace OAuth app or per-location API keys?
8. Which transactional email provider?
9. Which domains will be used: the platform domain for hosted booking pages, and whether wildcard subdomains are supported?
10. Which launch markets? US only at launch affects currency, tax, and compliance scope.
11. Will reseller and OTA channels (the 1% rate) be included in v1, or deferred?
12. Should we ask Stripe about the Connect platform revenue share once there is enough volume? (A business follow-up, not a build blocker.)

---

**End of specification.**
