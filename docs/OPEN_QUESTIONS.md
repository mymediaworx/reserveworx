# Open questions

Seeded from spec Section 18.2 on October 23, 2026. New questions discovered while scaffolding are listed after the original twelve. Michael approved keeping the fee rules merged in #11, #13, and #15 (decision D11). Questions 4, 5, 6, 11, and 26 stay open for a later change. They are not a reason to revert those commits.

## From Section 18.2

1. **SiteGround plan and access.** Partially answered. The product owner says GitHub Actions can SSH in and that `SG_PORT` is 18765. The plan name, PHP versions, memory, cron interval, MySQL version, and the rest of Section 2.2 are still unknown until **Hosting check** runs on `main`.
2. **Attorney review.** An attorney must confirm fee disclosure wording, all-in pricing, taxability of the booking fee, the e-signature process, and the waiver approach for the launch jurisdictions. Spec Section 7.6.7 asks for a per-location setting while that answer is open. `locations.booking_fee_taxable` is that setting. It defaults to off, so the booking fee is not taxed until an owner or manager turns it on for one location. Turning it on is not a legal conclusion.
3. **Waiver retention.** How long should waivers be kept by default after the activity date?
4. **Minimum booking fee.** Should there be a minimum per transaction? The spec default is none.
5. **Proportional fee refund.** Confirm Section 7.7 for partial refunds. The spec's proposed default is in place and is described in [`FEES.md`](FEES.md). Stripe's proportional application-fee refund does not match that basis. See [`INTEGRATIONS.md`](INTEGRATIONS.md).
6. **Staff-initiated Stripe payments.** Should walk-up payments carry the full 3%? Under D8 they currently do.
7. **GoHighLevel auth model.** Decided by Michael on October 24, 2026. There is no public Marketplace app and no OAuth app. A platform administrator links each workspace to one BrandWorx-managed sub-account and stores that sub-account's Location ID and Private Integration Token. The token is encrypted at rest and is not returned by any API or written to a log. See D12.
8. **Transactional email provider.** Postmark is the proposal. Not decided. The mailer remains `log`.
9. **Domains.** `app.reserveworx.com` is the platform host the product owner created. Wildcard subdomains and wildcard SSL are unverified. Public signup stores `{slug}.reserveworx.com` and marks it verified by platform attestation. The address does not resolve until that subdomain exists on SiteGround, or until wildcard DNS and SSL are confirmed.
10. **Launch markets.** US-only or not. This decides currency, tax, and compliance scope. Tenant currency is stored as `USD` and tenants cannot change it yet.
11. **Reseller and OTA channels.** Is the 1% channel in v1 or deferred? The column exists. No channel logic is implemented.
12. **Stripe Connect revenue share.** A business follow-up after there is volume. Not a build blocker.

## Found while scaffolding

13. **PHP on this site.** Managed PHP 8.2.35 was reported by BrandWorx Dev, distrosku.com, Oct 23 2026; not yet verified for this site. The app is Laravel 12 because of that report. Hosting check must still print the binary, `memory_limit`, and `max_execution_time`.
14. **Cron minimum.** The same report says Site Tools cron runs every minute on GoGeek. SiteGround's public fair-use note for shared plans still says at least 30 minutes between cron runs. Hosting check must show the real crontab. Hold expiry still has to be enforced at read time.
15. **MySQL or MariaDB on the host.** The exact version, InnoDB, transactions, and `SELECT … FOR UPDATE` are unverified until hosting-check runs. CI uses MySQL 8.0 and MariaDB 11.8.
16. **Outbound HTTPS and inbound WAF.** The hosting check can probe outbound TLS to Stripe and HighLevel. It cannot see WAF rules that would block inbound webhooks.
17. **Process limits and long-running processes.** Assumed unavailable until the check says otherwise. No persistent workers and no WebSockets.
18. **DNS verification of tenant domains.** Verification is interim platform attestation. Automated DNS is not built.
19. **White-label GoHighLevel install.** Moot for this sync. Michael decided on October 24, 2026 that there is no marketplace app to install. Linking is a Location ID and a Private Integration Token on a BrandWorx sub-account.
20. **HighLevel version header.** This sync sends `Version: 2021-07-28` because the Private Integration Token page describes API v2.0 and its examples use that header. The upsert, add-tag, create-note, and send-message reference pages list only `v3`. `GHL_VERSION` can override the header for every call, including `POST /conversations/messages`. A live call has not confirmed which value a sub-account accepts. The location-token path is unused because there is no OAuth exchange.
21. **Stripe API version and fee collector.** Do not pin an API version until payments are implemented. Before charging, confirm the connected account's fee collector (`controller.fees.payer` or Accounts v2 `defaults.responsibilities.fees_collector`).
22. **Data export before termination.** A platform administrator can store a private JSON export of one workspace. Termination stays refused until that file exists. The export does not delete the workspace. How long signed waivers are kept after termination is still open question 3.
23. **Backup and restore.** The required backup is the deploy workflow's `mysqldump` gzip at `laravel/shared/backups/<timestamp>.sql.gz`, written before migrations. The file must be non-empty, mode 600, and pass `gunzip -t`. `php artisan reserveworx:restore-backup {path} --force` replays that gzip into the configured database. The replay is covered by the test suite. A restore has not been run on the live SiteGround database. Site Tools backups are not the requirement.
24. **`QUEUE_WORK_MAX_TIME`.** Default is 20 seconds so a one-minute cron stays inside the reported 4,000 CPU-second GoGeek cap. Revisit after hosting-check prints the real limits.
25. **Frontend.** Answered October 23, 2026: the product owner approved Blade plus Livewire. See [`DECISIONS.md`](DECISIONS.md) and [`UI.md`](UI.md).
26. **Fee after an offline payment.** If a booking was partly paid offline and the balance is later paid through Stripe, does that first Stripe payment carry the full booking fee (literal Section 7.4), or only a fee on the amount paid through Stripe (D8)? The engine currently does the former.
27. **Customer erasure and waiver retention.** Erasing one customer keeps the waiver row and clears the name, guardian, IP address, and user agent. Booking, payment, refund, and fee rows stay so accounting and later refunds still have a record. How long the waiver itself is kept is still open question 3. This erasure does not choose that period.
28. **GoHighLevel send status.** `POST /conversations/messages` requires `status`. This build sends `pending`, which the schema lists alongside `delivered`, `failed`, and `read`. A live call has not confirmed that `pending` asks the location to deliver the email or SMS. The message body is not stored.
