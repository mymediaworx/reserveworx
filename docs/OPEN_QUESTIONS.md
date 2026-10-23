# Open questions

Seeded from spec Section 18.2 on October 23, 2026. New questions discovered while scaffolding are listed after the original twelve. None of these have been answered by a hosting probe or by a product-owner reply in this change.

## From Section 18.2

1. **SiteGround plan and access.** Partially answered. The product owner says GitHub Actions can SSH in and that `SG_PORT` is 18765. The plan name, PHP versions, memory, cron interval, MySQL version, and the rest of Section 2.2 are still unknown until **Hosting check** runs on `main`.
2. **Attorney review.** An attorney must confirm fee disclosure wording, all-in pricing, taxability of the booking fee, the e-signature process, and the waiver approach for the launch jurisdictions.
3. **Waiver retention.** How long should waivers be kept by default after the activity date?
4. **Minimum booking fee.** Should there be a minimum per transaction? The spec default is none.
5. **Proportional fee refund.** Confirm Section 7.7 for partial refunds. The spec's proposed default is in place and is described in [`FEES.md`](FEES.md). Stripe's proportional application-fee refund does not match that basis. See [`INTEGRATIONS.md`](INTEGRATIONS.md).
6. **Staff-initiated Stripe payments.** Should walk-up payments carry the full 3%? Under D8 they currently do.
7. **GoHighLevel auth model.** Marketplace OAuth is the engineering proposal. Per-location API keys and Private Integration Tokens are not the recommended primary model. Not decided.
8. **Transactional email provider.** Postmark is the proposal. Not decided. The mailer remains `log`.
9. **Domains.** `app.reserveworx.com` is the platform host the product owner created. Wildcard subdomains and wildcard SSL are unverified.
10. **Launch markets.** US-only or not. This decides currency, tax, and compliance scope. Tenant currency is stored as `USD` and tenants cannot change it yet.
11. **Reseller and OTA channels.** Is the 1% channel in v1 or deferred? The column exists. No channel logic is implemented.
12. **Stripe Connect revenue share.** A business follow-up after there is volume. Not a build blocker.

## Found while scaffolding

13. **PHP 8.3 on this account.** Laravel 13 requires it. SiteGround's managed default has been documented publicly as 8.2. The hosting check must show the binaries this account can actually run.
14. **Cron minimum.** SiteGround's public fair-use note for shared plans says at least 30 minutes between cron runs. If the check confirms that, hold expiry still has to be enforced at read time, and a Cloud plan or a small VPS is the upgrade to discuss. Do not migrate without approval.
15. **MySQL or MariaDB on the host.** Version, InnoDB, transactions, and `SELECT … FOR UPDATE` are unverified. CI uses MySQL 8.0.
16. **Outbound HTTPS and inbound WAF.** The hosting check can probe outbound TLS to Stripe and HighLevel. It cannot see WAF rules that would block inbound webhooks.
17. **Process limits and long-running processes.** Assumed unavailable until the check says otherwise. No persistent workers and no WebSockets.
18. **DNS verification of tenant domains.** Verification is interim platform attestation. Automated DNS is not built.
19. **White-label GoHighLevel install.** It is not verified that the BrandWorx agency at `app.brandworx.ai` can install a private marketplace app.
20. **HighLevel version header and location-token path.** Official pages disagree (`v3` versus `2021-07-28`, `/oauth/location-token` versus `/oauth/locationToken`). Confirm with a live call before Phase 6.
21. **Stripe API version and fee collector.** Do not pin an API version until payments are implemented. Before charging, confirm the connected account's fee collector (`controller.fees.payer` or Accounts v2 `defaults.responsibilities.fees_collector`).
22. **Data export before termination.** Terminating a tenant returns HTTP 409 until an export exists. The export is not built.
23. **Backup and restore.** Not tested. Production migrations stay off until a backup exists and the operator types the confirmation phrase in [`DEPLOY.md`](DEPLOY.md).
24. **`QUEUE_WORK_MAX_TIME`.** Default 50 seconds. Revisit if cron is 30 minutes.
25. **Frontend.** Blade plus Livewire for staff, token-based widget for embeds. Not approved. Livewire is not installed.
