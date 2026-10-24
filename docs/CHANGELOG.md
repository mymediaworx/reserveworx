# Changelog

Semantic versions start at the first tagged release. This file records what landed before that tag.

## Unreleased

- A deploy loads the Harbor Demo and Inland Demo sample shops when those workspaces exist, including kayaks, paddleboards, a sunset tour with departures for the next 60 days, prices, and opening hours. It does not change any other workspace. The same deploy adds `michael@brandworx.ai` as an owner of Harbor Demo when that account already exists.
- The SiteGround SSH wrapper retries a connection reset during key exchange. A remote command that already started is not run again.
- A verified tenant domain's `/` renders that workspace's public booking page. Staff sign-in stays at `/staff/login`.
- Staff availability and checkout API calls are rate limited per user and IP. The public booking widget keeps its own limit.
- A push to `main` deploys `app.reserveworx.com` and runs migrations. If `https://app.reserveworx.com/up` is not healthy, the code release is swapped back once. That check does not use localhost.
- The same deploy grants `michael@brandworx.ai` as a platform administrator. When that account is new, its password is written only on the server.
- A deploy writes `index.php` into an existing `harbor.reserveworx.com` document root so it boots the live app, then marks that hostname verified for Harbor Demo. It does not create the site and it does not write under `reserveworx.com`.
- `GET /admin` redirects to `/staff`. The older today page is no longer served.
- An offline payment records the customer total without the booking-fee line. The payment row still stores a fee of zero.
- Staff who can view bookings can list and open customers at `GET /api/v1/customers`. A search matches the stored name, email, or phone. The list is limited to 50.
- Today's report counts confirmed, cancelled, and no-show bookings that start that day, and gives each rate in basis points. Holds and unpaid checkouts stay out of the rate.
- `GET /api/v1/audit-logs` accepts optional `action` and `subject_id` filters. Omitting them still returns the workspace log.
- A `booking.refunded` notification hook includes the booking-fee portion of the customer refund beside the application-fee refund.
- A platform administrator can read stored booking-fee totals by workspace and stored channel at `GET /api/v1/platform/fee-revenue`. The report does not call Stripe.
- Today's report separates gross booking value, tax, booking fees, card payments, offline payments, and customer refunds.
- A signed waiver can be downloaded as a PDF of the stored text, hash, and signature record. That file is not an electronic-signature certificate.
- A platform administrator can export one workspace to a private JSON file. Closing that workspace for good stays refused until the file exists.
- Today's report can be downloaded as CSV from the staff page and from `GET /api/v1/reports/today/export`.
- Staff can refund a Stripe booking. A full refund returns the application fee with Stripe's flag. A partial refund returns the calculator's fee in a separate application-fee refund. A lost dispute does the same. Nothing is sent until the platform secret is set.
- GoHighLevel events queue in an outbox and are not sent. A mismatched workspace is refused. Notification hooks are marked skipped when no email provider is configured. `integrations:dispatch` does both.
- Staff can sign in on the tenant host and see today's bookings, departures, open checkouts, overdue rentals, and booking-fee totals. The same figures are available at `GET /api/v1/reports/today`. Heavier metrics stay off this live query.
- Maintenance and an unavailable asset flag future bookings and leave them in place. A late return records a per-minute fee when the workspace sets one. Waiver signatures store the template hash, IP, user agent, and a guardian name for a minor. This is not a claim of ESIGN or UETA compliance.
- Guests can look up a booking with its reference and email, cancel when the workspace allows it, and reschedule a confirmed booking. Card payments are not refunded from that page. Notification hooks for those actions are deduped.
- Token booking widget. Guests hold a time and see the booking fee before payment. The widget does not use a cookie session, and the return page does not confirm a card payment.
- Pricing snapshots, promo codes, Stripe Connect direct-charge checkout, and webhook confirmation. Online checkout stays off until the platform Stripe secret is set and the connected account can take card payments.
- Locations, resource categories, individually tracked assets, and quantity pools.
- Opening hours, blackouts, services, and resource requirements. Availability responses outside the allocation transaction are hints.
- Atomic holds, confirmations, extensions, cancellations, reschedules, and asset reassignment, with the Section 4.7 concurrency suite.
- Calendar day, week, month, and agenda windows, including a staff-member filter on the member who created the booking.
- Service minimum and maximum party size. A short party is flagged and an oversized party is refused.
- Tour departures, private and group seat caps, a configured waitlist, and no-show release of inventory.
- Automatic waitlist promotion into a hold, with a queued notification hook and no email.
- Guide conflict checks for a staff user and for a bookable guide asset.
- Named rental lengths in minutes or hours, a turnaround gap, and active-checkout overlap that extends when a rental runs past its end.
- Phase 1 documents: hosting checklist, allocation design, schema, integrations, and fees.
- Laravel 12 foundation on PHP 8.2: tenants, domains, memberships, Sanctum auth, roles, platform administration, and audit logs.
- CI runs on PHP 8.2 against MySQL 8.0 and MariaDB 11.8.
- SiteGround deploy keeps one rollback release, deletes the upload archive, and only touches `app.reserveworx.com`.
- GitHub Actions CI (Pint, PHPStan, Composer audit, MySQL 8 tests) and Dependabot.
- Manual SiteGround hosting check and deploy workflows. Neither has been run against the host from this change.
