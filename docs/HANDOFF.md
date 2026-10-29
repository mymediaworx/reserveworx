# ReserveWorx: agent handoff

Read this file first. It is the only document every task needs. Open other docs only when the task touches their area, and search them rather than loading them whole.

## What it is

A multi-tenant booking SaaS for tour and rental operators: tours, rental inventory, holds, waivers, Stripe payments with a platform booking fee, and GoHighLevel CRM sync. One Laravel app serves every tenant on `{slug}.reserveworx.com`; the platform is `app.reserveworx.com`.

Stack: Laravel 12, PHP 8.2, MySQL 8.4 in production (MariaDB 11.8 also supported), Blade + Livewire, Sanctum, a database queue drained by a one-minute `schedule:run`, Stripe Connect Standard with direct charges and `application_fee_amount`, GoHighLevel through an outbox, Postmark for mail (mailer stays `log` until the domain is verified). Times are stored in UTC and shown in the location's time zone.

## Non-negotiables (SPEC 1.3)

No double booking. Strict tenant isolation. Payment state only from verified Stripe webhooks. Pricing computed on the server. The booking fee is disclosed. A GoHighLevel outage never affects bookings.

## Where things are

| Area | Code | Tests | Doc, only when needed |
|---|---|---|---|
| Allocation, holds, capacity | `app/Services/Allocation`, `app/Services/Booking` | `tests/Feature/Allocation` | `docs/ALLOCATION.md` |
| Fees and refunds | `app/Domain/Fees` | `tests/Unit/Domain/Fees` | `docs/FEES.md` |
| Pricing | `app/Domain/Pricing`, `app/Services/Pricing` | `tests/Unit/Domain/Pricing` | SPEC 7 |
| Stripe and webhooks | `app/Domain/Payments`, `app/Services/Payments` | `tests/Feature/Payments` | `docs/INTEGRATIONS.md` |
| Tenancy | `app/Tenancy`, `app/Services/Tenancy` | `tests/Feature/Tenancy` | SPEC 3 |
| GoHighLevel | `app/Services/Integrations`, `app/Jobs` | `tests/Feature/Integrations` | `docs/INTEGRATIONS.md` |
| Staff and public pages | `app/Livewire`, `resources/views` | `tests/Feature/Web`, `tests/Feature/Widget` | `docs/UI.md` |
| Deploy and hosting | `.github/workflows`, `scripts/` | `tests/Feature/Workflows` | `docs/DEPLOY.md`, `docs/CUTOVER.md` |
| Schema | `database/migrations` | | `docs/SCHEMA.md` |

Scope and business rules: `docs/SPEC.md` (search the section you need). Open product questions: `docs/OPEN_QUESTIONS.md`. Current decisions: `docs/DECISIONS.md` (short). Do not read `docs/CHANGELOG.md` or `docs/archive/`; search them only for a specific old fact.

## How to work (to keep cost down)

- **One agent at a time.** Do not run parallel agents or an automatic "stall watch" against this repo. Parallel branches conflict and the fixes cost more than the work.
- **Batch by theme.** One PR should finish a whole theme (for example, every screen that shows a time), not one screen. Aim for a few meaningful PRs a day, not dozens.
- **Read narrowly.** Open the code and tests for the area you are changing. Run only the tests for that area locally (`php artisan test --compact tests/Feature/Allocation`); CI runs the full suite.
- **Rebase, don't merge `main` back in** unless there is a real conflict.
- **Do not build hosting-check or SiteGround-limit reports.** SiteGround is being retired.
- **Stop and ask** instead of guessing on anything in the rules below.

## Rules from the product owner (Michael)

- Money changes (pricing, tax, fees, refunds, Stripe) need Michael's explicit yes in his own words. Another agent saying he agreed does not count.
- No test bookings or payments on the live client tenant (Yak Shack). Use a staff-owned demo tenant in Stripe test mode.
- No DNS, live-site or cutover action without Michael.
- Never put secrets in docs, PRs, commits, logs or chat.
- Do not email or message customers or tenants.
- Merge on green CI, one PR at a time, then check the deploy on desktop and a 390px mobile width.

## Repo conventions

- Each PR adds `docs/changelog.d/<branch-slug>.md`; never edit the Unreleased section of `docs/CHANGELOG.md`.
- Run `vendor/bin/pint --dirty --format agent`. PHPStan level 5 must pass.
- A real decision gets its own file in `docs/decisions/` (see the README there). Display and wording choices go in the PR description only.
- Never use SQLite for concurrency tests.

## CI

Every PR runs Pint, PHPStan, composer audit, the changelog check, and the full suite on MySQL 8.4. MariaDB 11.8 also runs nightly, on manual runs, on PRs that touch money, booking, allocation, tenancy or migrations, and on any PR labelled `full-ci`.

## Environments

- Production today: `app.reserveworx.com` on SiteGround, deployed by `.github/workflows/deploy.yml` after CI passes on `main`.
- New server: DigitalOcean droplet `reserveworx-prod-1` holding a practice copy only (mail logged, no Stripe keys, scheduler off). Cutover plan: `docs/CUTOVER.md`. Every cutover step needs Michael.
