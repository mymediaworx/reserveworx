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
| Calendar staff filter | `staff_user_id` matches `bookings.created_by`, the member who placed the hold | The assigned guide is `bookings.guide_user_id`. The calendar filter stays on the member who created the booking |
| Party size | `max_participants` rejects the hold. A party under `min_participants` is stored and returned as `below_minimum`. A departure reports the same flag from the headcount of its live bookings | Spec Section 5 says a tour below its minimum is flagged for the operator, not refused |
| Tour departures | A departure is one tour time. `private` allows one live booking. `seats`, or the service maximum when seats are empty, caps the combined party. The first live booking allocates the service resources. Later parties on that departure take a seat only. Moving one means booking another departure | A guide or boat on the tour is not consumed once per customer |
| Waitlist | When a cancel, no-show, expired-hold purge, or early return frees capacity, the oldest waiting entry for that service and overlapping interval is promoted into a hold. A party that does not fit is skipped. Promotion uses the same allocator lock order and queues a `waitlist.promoted` notification hook. No email is sent | Michael, October 23, 2026. The hook is the stand-in until an email provider is chosen |
| Guides | A booking may name an active staff user in `guide_user_id` and a bookable asset with `is_guide`. Each is conflict-checked. The user lock is last, after assets. Parties on the same departure share that guide | Michael, October 23, 2026. Reassignment still moves an asset. The calendar staff filter stays on `created_by` |
| No-show | Marking a held or confirmed booking as `no_show` deletes its hold and allocation | The slot becomes free. Payment status is left unchanged until refunds exist |
| Reschedule and reassign | Confirmed bookings only. Same lock order and live recheck as a new allocation. The original rows stay if the recheck fails | Spec Section 5. Reassignment stays inside one category and location |
| Rental lengths | A service may offer named durations in minutes or hours. A rental whose length matches none of them is refused. A departure keeps the departure's own times | Michael, October 23, 2026. Half day is 4 hours. A 90 minute product is stored as 90 minutes |
| Slot blockers | Allocations, unexpired holds, and active checkouts block a slot. `turnaround_minutes` extends the buffered end. An unreturned checkout occupies the resource until `max(due_at, now()) + turnaround`. Returning sets the allocation end to `now() + turnaround` | Michael, October 23, 2026. An overdue checkout keeps the block moving until the return is recorded |
| Stripe accounts | Accounts v2, `dashboard: full`, Stripe collects fees and losses, `card_payments` requested. Direct charges use Checkout Sessions on the connected account with `application_fee_amount`. Readiness is `card_payments.status === active`. API version `2026-09-30.preview` | D1 asked for Standard accounts. Stripe's current Accounts API has no `type: standard`. This is the v2 shape that keeps the tenant as merchant of record and Stripe as the processor. No live keys are stored |
| Booking widget | Blade pages addressed by an unguessable embed token. Checkout and payment URLs are signed and expire with the hold. No session cookie and no Livewire | Spec Section 6 forbids third-party cookie checkout. Livewire is still an unapproved proposal |
| Guest cancellation | Off until `guest_cancel_before_minutes` is set. A guest who knows the reference and email can cancel a held or confirmed booking only before that lead time. No card refund is issued | The cancellation policy is the tenant's. The default does not invent a refund. Reschedule uses the same allocator as staff |
| Unavailable resources | A maintenance window, or an asset moved to maintenance or blocked, writes `attention_reason` on future held and confirmed bookings that use it. Status is not changed | Spec Section 9. The operator decides whether to move the guest |
| Late fee | `late_fee_per_minute_cents` is null until set. A return after `due_at` stores whole minutes times that rate on the checkout. It is not sent to Stripe | Charging the fee is a later payment. The amount is only a record |
| Waivers | Versioned text, signature hash, IP, user agent, and a required guardian name for a minor. A signed waiver downloads as a text PDF of that stored record | Not an attorney-approved ESIGN or UETA process. Retention is still an open question |
| Staff dashboard | Blade on the tenant host, session or Sanctum, no Livewire. Today's rows are a live query. Gross, tax, and the booking fee follow bookings that start that day. Card, offline, and refund amounts follow the day they were recorded. The net booking-fee total is all-time. Utilization and conversion wait for a summary job | Spec Section 11. A shared-host page should not scan history for heavy metrics |
| GoHighLevel outbox | Events are stored per tenant and refused when the workspace does not match. Dispatch records a not-connected error and does not call HighLevel. Email hooks are marked skipped with no mailer | D10 and spec Sections 8 and 10. OAuth and the email provider are still unapproved |
| Fee refunds | The Section 7.7 calculator chooses the amount. Stripe's `refund_application_fee` flag is used only for a full refund with no earlier refund. Partials use a separate application fee refund | Spec Section 7.7. A partial flag would use the whole charge, including tax and the fee |
| Tenant data export | A platform administrator writes one workspace to a private JSON file. The read uses that workspace's tenant context, and a row from any other workspace aborts the file. Member passwords are omitted. Any completed file unlocks termination. The file is a snapshot and does not delete data | Spec Section 3. Waiver retention after termination is still open |
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

**Approved by the product owner on October 23, 2026.** Livewire 4 is installed. Pages are server-rendered Blade with Livewire components, and there is no Node build. Details are in [`UI.md`](UI.md).

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
