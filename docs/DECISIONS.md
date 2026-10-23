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
| Queue | `database` driver, `schedule:run` from cron, `queue:work database --stop-when-empty --tries=3 --max-time` | Spec Section 2.3. Default max time is 50 seconds (`QUEUE_WORK_MAX_TIME`) |
| Hold expiry | Must be enforced at read time once the engine exists | Cron may be slower than one minute. The engine is not written yet |
| Roles | System roles `owner`, `manager`, `staff` | Foundation default so policies can be tested. Not a product-owner staffing model |
| Laravel major | 13.x, PHP ^8.3 | Current release. Security support runs through March 17, 2028. Contingent on the host offering PHP 8.3 |
| Test database | MySQL 8, user `reserveworx`, password `reserveworx` | Disposable CI credential. SQLite is not used because it ignores row locks |
| Branch names | This change uses `cursor/reserveworx-foundation-681b` | Spec Section 16 prefers `feature/<issue>-<slug>`. No GitHub issue was opened for this run |

## Proposals awaiting product-owner approval

### Frontend

Use Blade and Livewire for first-party admin and staff pages. Those pages run on the tenant's own domain and on `app.reserveworx.com`, where first-party cookies are allowed.

The embeddable booking widget must not use cookie sessions. Spec Section 6 assumes it can be framed on someone else's site, and third-party cookies are blocked. The widget should be token-authenticated server-rendered pages, or a small JavaScript client built in GitHub Actions and copied into the release. The SiteGround server must not run Node.

Inertia with Vue was considered. It can be built before deploy, so it also needs no Node at runtime, but it still needs a Node build and it fights the token-in-iframe model. It is not the recommendation.

Livewire is not installed. Do not add it until this proposal is approved.

### Transactional email

Use Postmark. Laravel 13 documents the Postmark transport (`symfony/postmark-mailer`, `POSTMARK_API_KEY`, `message_stream_id`). Postmark webhooks cover Delivery, Bounce, and Spam Complaint. Postmark's comparison with Amazon SES says transactional and broadcast mail use separate streams and that Postmark does not require a sending-quota increase. Cost was not compared.

Mailgun and Amazon SES remain acceptable. The default mailer stays `log`. Do not install an email package, and do not use PHP `mail()`.

### GoHighLevel authentication

Use a Marketplace OAuth app, start Private, target Sub-account, and store location-level tokens. Do not use retired v1 API keys. Do not use a Private Integration Token as the primary multi-tenant model: HighLevel's own guidance describes that token as internal, for one sub-account, and for cases that do not need webhooks.

This is open question 7. Details and citations are in [`INTEGRATIONS.md`](INTEGRATIONS.md).

### Laravel 12 fallback

If the hosting check shows that this account cannot run PHP 8.3 or newer, the scaffold should move to Laravel 12, which supports PHP 8.2 and is security-only until February 24, 2027. Do not downgrade until that report exists.

## Explicitly not decided

Fee disclosure copy, waiver retention, launch markets, currency, tax, a minimum booking fee, reseller scope, and any move off SiteGround. Those stay in [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md).
