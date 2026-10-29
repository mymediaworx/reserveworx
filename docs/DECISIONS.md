# Decision log

This is the short, current log. The full history up to October 29, 2026 (about 270 per-screen entries) is in [`archive/DECISIONS-2026-10-29.md`](archive/DECISIONS-2026-10-29.md). Read the archive only when a task needs one specific old entry; search it, do not load it whole.

**New decisions go in their own file** under [`decisions/`](decisions/README.md), never appended here. That keeps parallel pull requests from conflicting on this file. Proposals are not approved until the product owner says so in his own words.

## Approved by the product owner

| ID | Decision |
|---|---|
| D1 | Stripe Connect, Standard accounts, direct charges, Stripe-owned processing pricing |
| D2 | No subscription. Revenue comes from the application fee only |
| D3 | Fee rates set at roughly half of FareHarbor's: 3% direct and 1% third-party or API |
| D4 | Each tenant chooses who pays the fee: customer-paid (default) or operator-absorbed |
| D5 | Fee disclosures must meet Section 7.6 |
| D6 | Fee refunds follow the published Section 7.7 rules |
| D7 | The full fee is collected on the first Stripe payment, including deposits |
| D8 | The fee is charged only on Stripe payments. Offline payments carry no fee |
| D9 | Laravel, PHP, and MySQL or MariaDB on SiteGround, subject to verification |
| D10 | GoHighLevel is never the source of truth for inventory |
| D11 | The fee rules merged in #11, #13, and #15 stay. Michael approved them on October 23, 2026. Do not revert those commits |
| D12 | No public GoHighLevel Marketplace or OAuth app. A platform administrator links each workspace to one BrandWorx sub-account and stores its Location ID and Private Integration Token. The token is encrypted at rest and is never returned by an API or written to a log. Michael approved this on October 24, 2026 |
| D13 | Public self-serve signup at `app.reserveworx.com/signup`. The workspace starts in onboarding with the standard fee defaults. The owner verifies their email before that workspace can go active from the checklist. Michael approved this on October 24, 2026 |
## Proposal status

- **Frontend:** Blade and Livewire. Approved October 23, 2026. See [`UI.md`](UI.md).
- **Transactional email:** Postmark proposed; the mailer stays `log` until the Postmark domain is verified. PR #278 is held for that.
- **GoHighLevel authentication:** superseded by D12.
- **PHP version:** Laravel 12 on PHP 8.2 (the droplet runs 8.3). Do not move to Laravel 13 until production is confirmed on PHP 8.3 or newer.

## Core engineering choices

These carry the architecture. Per-screen choices (time-zone labels, layout, wording) are in the archive.

| Topic | Choice | Why |
|---|---|---|
| Identifiers | ULIDs on application tables | Spec Section 3 asks for immutable ULID or UUID identifiers |
| Tenant isolation | Fail-closed global scope, policies, and endpoint tests | Spec Section 3. A missing tenant context matches nothing |
| Client tenant id | Rejected unless it equals the domain-resolved tenant | A client-supplied id is never trusted on its own |
| Platform hosts | `app.reserveworx.com` never implies a tenant | Local tests also allow `localhost` and `127.0.0.1`. Production `.env` must not |
| Domain verification | Interim platform attestation, audited | DNS automation is not built. This is not a claim that DNS was checked |
| Password hashing | bcrypt via Laravel's default | argon2id waits until `memory_limit` is known |
| Queue | `database` driver, `schedule:run` from cron, `queue:work database --stop-when-empty --tries=3 --max-time` | Spec Section 2.3. Default max time is 20 seconds so a one-minute cron stays inside the reported GoGeek CPU cap |
| Deploy gate | Deploy runs on `workflow_run` after **CI** completes for a push to `main`. A gate job deploys the CI commit only when CI succeeded and that commit is still the tip of `main`. Migrations, role sync, and caches run from the new release before `current` moves. The schedule writes a heartbeat every minute that smoke, the hosting check, and `/platform` read | Issues #129, #130, #131. A failed CI run used to reach production, and new code briefly ran against the old schema |
| Hold expiry | Enforced at read time with `expires_at > now()`. `holds:purge-expired` only deletes rows | Cron may be slower than one minute. A late cleanup must not keep a slot blocked |
| Tax on the booking fee | One boolean per location, default off. On applies the customer booking fee to rental tax rates only. The platform fee is unchanged. Operator-absorbed quotes are unchanged. Later price changes follow the snapshot flag | Spec Section 7.6.7. Open question 2 still asks an attorney whether a jurisdiction should turn it on |
| Allocation transaction | `READ COMMITTED`, pool row then ledger buckets, then assets. Three retries on 1205 and 1213 | A repeatable-read snapshot would miss a commit that landed while this transaction waited |
| Capacity ledger length | `/staff/settings` saves `ledger_bucket_minutes` as 5, 10, 15, 30, or 60. The default stays 15. Staff can read it. Existing `capacity_ledger` rows are not rewritten. The next pool lock uses the saved length | Spec Section 4.3. The API already accepted those values. This is the staff control. It does not change a price, a payment, a tax, a fee, or a refund |
| Asset overlap | One asset, one overlapping booking. Capacity on the asset is recorded and does not allow a second overlap | A specific boat or guide is exclusive. Overlapping up to `capacity` is a product decision |
| Stripe accounts | Accounts v2, `dashboard: full`, Stripe collects fees and losses, `card_payments` requested. Direct charges use Checkout Sessions on the connected account with `application_fee_amount`. Readiness is `card_payments.status === active`. API version `2026-09-30.preview` | D1 asked for Standard accounts. Stripe's current Accounts API has no `type: standard`. This is the v2 shape that keeps the tenant as merchant of record and Stripe as the processor. No live keys are stored |
| Checkout window | A held or pending-payment booking's Checkout Session sets `expires_at` to the later of the hold expiry and now plus 31 minutes. The holds are extended to that time plus 5 minutes before the session is created. A booking that is not an open hold keeps Stripe's default. Public confirmation links last until the booking ends plus 7 days, and at least a day | Issues #132 and #133. Stripe requires at least 30 minutes. A session that outlived its hold let a customer pay after losing the slot |
## Explicitly not decided

Fee disclosure copy, waiver retention, launch markets, currency, tax, a minimum booking fee, reseller scope, and the date of the cutover off SiteGround. Those stay in [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md).
