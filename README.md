# ReserveWorx

Multi-tenant booking and rental platform for BrandWorx. The product spec is [`docs/SPEC.md`](docs/SPEC.md) (v1.0, October 23, 2026).

This repository is the Phase 1 architecture package and the Phase 2 foundation: tenant registry, domain and membership resolution, authentication, roles, audit logging, and CI. The allocation engine, payments, and GoHighLevel client are not implemented.

## Requirements

- PHP 8.3 or newer
- Composer 2
- MySQL 8 for the application and the test suite
- No Node process at runtime. The current UI is a static Blade page.

Laravel 13 is installed. It requires PHP 8.3. Whether this SiteGround account can select PHP 8.3 is still unverified. See [`docs/HOSTING.md`](docs/HOSTING.md) and [`docs/DECISIONS.md`](docs/DECISIONS.md).

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set `DB_*` in `.env` to a local MySQL database, then:

```bash
php artisan migrate
php artisan serve
```

`/` and `/up` should return 200. The API lives under `/api/v1`.

There is no default user. Create a platform administrator only after a user exists:

```bash
php artisan reserveworx:grant-platform-admin you@example.com
```

## Tests

PHPUnit uses MySQL, not SQLite. The disposable test credential is `reserveworx` / `reserveworx` on database `reserveworx_test` at `127.0.0.1`. It is not a production secret.

```bash
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
composer audit
```

## Documentation

| File | Contents |
|---|---|
| [`docs/SPEC.md`](docs/SPEC.md) | Product source of truth |
| [`docs/DECISIONS.md`](docs/DECISIONS.md) | Approved decisions and proposals waiting on the product owner |
| [`docs/OPEN_QUESTIONS.md`](docs/OPEN_QUESTIONS.md) | Questions that still need a product-owner answer |
| [`docs/HOSTING.md`](docs/HOSTING.md) | Section 2.2 checklist. Hosting facts are not verified yet |
| [`docs/ALLOCATION.md`](docs/ALLOCATION.md) | Allocation design. No engine code is in this repository |
| [`docs/SCHEMA.md`](docs/SCHEMA.md) | ERD and which tables exist now |
| [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md) | Stripe Connect and GoHighLevel research |
| [`docs/FEES.md`](docs/FEES.md) | Fee rules. The calculator is not implemented |
| [`docs/DEPLOY.md`](docs/DEPLOY.md) | How production deploys and rollbacks work |
| [`docs/RUNBOOK.md`](docs/RUNBOOK.md) | What to do when something fails |
| [`docs/CHANGELOG.md`](docs/CHANGELOG.md) | Release notes |

## Hosting check and deploy

Both workflows are manual and run only from `main` after this pull request is merged. They use the `SG_HOST`, `SG_USER`, `SG_PORT`, `SG_SSH_KEY`, and optional `SG_SSH_PASSPHRASE` repository secrets. They do not print those secrets.

1. Merge the pull request into `main`.
2. Open `https://github.com/mymediaworx/reserveworx/actions`.
3. Choose **Hosting check**, then **Run workflow**, and select branch `main`.
4. Open the run and read the job summary at the top. Copy the facts into `docs/HOSTING.md` in a follow-up change. Do not paste secrets.
5. Deploy only after the hosting report is acceptable and `$HOME/reserveworx/shared/.env` exists on the server. Actions → **Deploy** → **Run workflow** → branch `main` → action `deploy`. Leave **Run migrations** off.
6. Rollback: the same workflow, action `rollback`. Rollback does not reverse schema changes.

Migrations stay off unless a current SiteGround backup exists and the operator types `I have a backup` in the confirmation field. Details are in [`docs/DEPLOY.md`](docs/DEPLOY.md).

## Status

Foundation API and tests are in this branch. Blocked on product-owner decisions in [`docs/OPEN_QUESTIONS.md`](docs/OPEN_QUESTIONS.md) and on the first real hosting-check run. Do not treat SiteGround compatibility as confirmed.
