# ReserveWorx

Multi-tenant booking and rental platform for BrandWorx. The product spec is [`docs/SPEC.md`](docs/SPEC.md) (v1.0, October 23, 2026).

This repository is the ReserveWorx application. Local setup below is a first install. An upgrade of a site that is already serving is the Upgrade section of [`docs/DEPLOY.md`](docs/DEPLOY.md). Platform administrators read those same steps at `/platform/upgrade`.

## Requirements

- PHP 8.2 or newer (production target is the reported managed PHP 8.2.35)
- Composer 2
- MySQL 8 or MariaDB 11 for the application. CI runs both MySQL 8.0 and MariaDB 11.8
- No Node process at runtime. The current UI is a static Blade page.

Laravel 12 is installed, with Composer `platform.php` set to `8.2.35`. That PHP version was reported by BrandWorx Dev for distrosku.com on October 23, 2026, and is not yet verified for this site. `memory_limit`, `max_execution_time`, and the exact database version are still unknown until the hosting check runs. See [`docs/HOSTING.md`](docs/HOSTING.md).

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set `DB_*` in `.env` to a local MySQL 8 or MariaDB 11 database, then:

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

PHPUnit uses MySQL or MariaDB, not SQLite. CI runs the same suite on MySQL 8.0 and MariaDB 11.8, including the row-lock test. The disposable test credential is `reserveworx` / `reserveworx` on database `reserveworx_test` at `127.0.0.1`. It is not a production secret.

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
| [`docs/CHANGELOG.md`](docs/CHANGELOG.md) | Release notes. A pull request adds a file in [`docs/changelog.d/`](docs/changelog.d/README.md) instead of editing Unreleased |

## Hosting check and deploy

Both workflows are manual and run only from `main` after this pull request is merged. They use the `SG_HOST`, `SG_USER`, `SG_PORT`, `SG_SSH_KEY`, and optional `SG_SSH_PASSPHRASE` repository secrets. They do not print those secrets.

1. Merge the pull request into `main`.
2. Open `https://github.com/mymediaworx/reserveworx/actions`.
3. Choose **Hosting check**, then **Run workflow**, and select branch `main`.
4. Open the run and read the job summary at the top. Copy the facts into `docs/HOSTING.md` in a follow-up change. Do not paste secrets.
5. Deploy only after the hosting report is acceptable and `$HOME/www/app.reserveworx.com/laravel/shared/.env` exists on the server. Actions → **Deploy** → **Run workflow** → branch `main` → action `deploy`. Leave **Run migrations** off. HTTPS must already be enforced for that subdomain in Site Tools.
6. Rollback: the same workflow, action `rollback`. Rollback does not reverse schema changes.

Migrations stay off unless **Run migrations** is enabled. The deploy then writes a verified `mysqldump` gzip under `laravel/shared/backups` before `artisan migrate` and fails if that file is empty or `gunzip -t` fails. Details are in [`docs/DEPLOY.md`](docs/DEPLOY.md).

## Status

The application is in active development before the first tagged release. Open product-owner questions stay in [`docs/OPEN_QUESTIONS.md`](docs/OPEN_QUESTIONS.md). Do not treat SiteGround compatibility as confirmed until hosting check has been read.
