# Changelog

Semantic versions start at the first tagged release. This file records what landed before that tag.

## Unreleased

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
