# Changelog

Semantic versions start at the first tagged release. This file records what landed before that tag.

## Unreleased

- Locations, resource categories, individually tracked assets, and quantity pools.
- Opening hours, blackouts, services, and resource requirements. Availability responses outside the allocation transaction are hints.
- Atomic holds, confirmations, extensions, and cancellations, with the Section 4.7 concurrency suite.
- Phase 1 documents: hosting checklist, allocation design, schema, integrations, and fees.
- Laravel 12 foundation on PHP 8.2: tenants, domains, memberships, Sanctum auth, roles, platform administration, and audit logs.
- CI runs on PHP 8.2 against MySQL 8.0 and MariaDB 11.8.
- SiteGround deploy keeps one rollback release, deletes the upload archive, and only touches `app.reserveworx.com`.
- GitHub Actions CI (Pint, PHPStan, Composer audit, MySQL 8 tests) and Dependabot.
- Manual SiteGround hosting check and deploy workflows. Neither has been run against the host from this change.
