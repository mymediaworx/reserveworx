# Decision log

Product-owner decisions D1–D10 were made on October 23, 2026 and are copied from spec Section 18.1. They are not reopened here.

Engineering notes below are implementation choices. Proposals are not approved until the product owner says so.

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

The product owner also created the subdomain `app.reserveworx.com` as the application target, and stated that GitHub Actions can SSH to SiteGround using the repository secrets `SG_HOST`, `SG_USER`, `SG_PORT` (18765), `SG_SSH_KEY`, and possibly `SG_SSH_PASSPHRASE`. That statement is recorded as access intent. It is not a completed Section 2.2 inspection.

## Engineering choices in this repository

| Topic | Choice | Why |
|---|---|---|
| Identifiers | ULIDs on application tables | Spec Section 3 asks for immutable ULID or UUID identifiers |
| Tenant isolation | Fail-closed global scope, policies, and endpoint tests | Spec Section 3. A missing tenant context matches nothing |
| Client tenant id | Rejected unless it equals the domain-resolved tenant | A client-supplied id is never trusted on its own |
| Platform hosts | `app.reserveworx.com` never implies a tenant | Local tests also allow `localhost` and `127.0.0.1`. Production `.env` must not |
| Domain verification | Interim platform attestation, audited | DNS automation is not built. This is not a claim that DNS was checked |
| Password hashing | bcrypt via Laravel's default | argon2id waits until `memory_limit` is known |
| Queue | `database` driver, `schedule:run` from cron, `queue:work database --stop-when-empty --tries=3 --max-time` | Spec Section 2.3. Default max time is 20 seconds so a one-minute cron stays inside the reported GoGeek CPU cap |
| Hold expiry | Enforced at read time with `expires_at > now()`. `holds:purge-expired` only deletes rows | Cron may be slower than one minute. A late cleanup must not keep a slot blocked |
| Allocation transaction | `READ COMMITTED`, pool row then ledger buckets, then assets. Three retries on 1205 and 1213 | A repeatable-read snapshot would miss a commit that landed while this transaction waited |
| Asset overlap | One asset, one overlapping booking. Capacity on the asset is recorded and does not allow a second overlap | A specific boat or guide is exclusive. Overlapping up to `capacity` is a product decision |
| Staff bookings | Staff have `bookings.manage` | Spec Section 5 includes staff-created bookings |
| Inventory permissions | `inventory.view` for owner, manager, and staff. `inventory.manage` for owner and manager | Staff can see resources. They cannot change quantity, status, or buffers |
| Location timezone | Timezone is stored on `locations`, not tenants | Spec Section 4 and SCHEMA.md |
| Opening hours | A location with no schedule rows is open all day. Pickup and the instant before return must sit in a window. Overnight between those instants does not have to. Equal open and close times are an empty window | Matches a shop that must be open when a rental leaves and comes back. The product owner can require the whole interval to be inside opening hours later |
| Blackouts | A blackout blocks every local date the half-open customer interval touches. A null location applies to every location of the tenant | Spec Section 4.2. Buffers are not what the blackout is measured against |
| Resource requirements | `quantity` is per booking. `per_participants` scales it with `ceil(participants / per_participants) * quantity` | Covers "1 guide per 8 participants" without a separate rule language |
| Roles | System roles `owner`, `manager`, `staff` | Foundation default so policies can be tested. Not a product-owner staffing model |
| Laravel major | 12.x, PHP ^8.2, Composer platform `8.2.35` | Managed PHP 8.2.35 was reported by BrandWorx Dev, distrosku.com, Oct 23 2026; not yet verified for this site. Laravel 12 supports PHP 8.2. Laravel 13 requires PHP 8.3 |
| Test databases | MySQL 8.0 and MariaDB 11.8, user `reserveworx`, password `reserveworx` | Disposable CI credentials. The row-lock test runs on both. SQLite is not used because it ignores row locks |
| Release files | Current release plus one rollback, archive deleted after extract, vendor only inside those two trees | Reported GoGeek inode cap is 600,000 and that account already exceeded it. Deploy never writes outside `app.reserveworx.com` |
| Branch names | This change uses `cursor/reserveworx-foundation-681b` | Spec Section 16 prefers `feature/<issue>-<slug>`. No GitHub issue was opened for this run |

## Proposals awaiting product-owner approval

### Frontend

Use Blade and Livewire for first-party admin and staff pages. Those pages run on the tenant's own domain and on `app.reserveworx.com`, where first-party cookies are allowed.

The embeddable booking widget must not use cookie sessions. Spec Section 6 assumes it can be framed on someone else's site, and third-party cookies are blocked. The widget should be token-authenticated server-rendered pages, or a small JavaScript client built in GitHub Actions and copied into the release. The SiteGround server must not run Node.

Inertia with Vue was considered. It can be built before deploy, so it also needs no Node at runtime, but it still needs a Node build and it fights the token-in-iframe model. It is not the recommendation.

Livewire is not installed. Do not add it until this proposal is approved.

### Transactional email

Use Postmark. Laravel 12 documents the Postmark transport (`symfony/postmark-mailer`, `POSTMARK_API_KEY`, `message_stream_id`). Postmark webhooks cover Delivery, Bounce, and Spam Complaint. Postmark's comparison with Amazon SES says transactional and broadcast mail use separate streams and that Postmark does not require a sending-quota increase. Cost was not compared.

Mailgun and Amazon SES remain acceptable. The default mailer stays `log`. Do not install an email package, and do not use PHP `mail()`.

### GoHighLevel authentication

Use a Marketplace OAuth app, start Private, target Sub-account, and store location-level tokens. Do not use retired v1 API keys. Do not use a Private Integration Token as the primary multi-tenant model: HighLevel's own guidance describes that token as internal, for one sub-account, and for cases that do not need webhooks.

This is open question 7. Details and citations are in [`INTEGRATIONS.md`](INTEGRATIONS.md).

### PHP version

The scaffold is Laravel 12 because managed PHP 8.2.35 was reported for what is likely this account. That report is not a measurement of `app.reserveworx.com`. Hosting check still has to print the binary, `memory_limit`, and `max_execution_time`. Laravel 12 receives security fixes until February 24, 2027. Do not move back to Laravel 13 unless this site can select PHP 8.3 or newer.

## Explicitly not decided

Fee disclosure copy, waiver retention, launch markets, currency, tax, a minimum booking fee, reseller scope, and any move off SiteGround. Those stay in [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md).
