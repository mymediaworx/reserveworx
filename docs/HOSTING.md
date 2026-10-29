# Hosting checklist

Status: **partially verified**. An SSH session on October 23, 2026 measured the account layout and CLI tools. Deploy run [40080763617](https://github.com/mymediaworx/reserveworx/actions/runs/40080763617) on October 24, 2026 measured the application database, the InnoDB row lock, the deploy PHP limits, and the queue schedule. Cells that neither run measured stay unverified. No secret values are stored in this file.

The product owner created `app.reserveworx.com` and stored GitHub Actions secrets `SG_HOST`, `SG_USER`, `SG_PORT` (value stated as 18765), `SG_SSH_KEY`, and possibly `SG_SSH_PASSPHRASE`. Those secrets were not read while this repository was built. The workflows that use them are `.github/workflows/hosting-check.yml` and `.github/workflows/deploy.yml`. Deploy runs `reserveworx:hosting-check` after activate and prints the output below. Do not paste secrets into this file.

## How to collect the facts

1. Merge the foundation pull request into `main`.
2. GitHub → Actions → **Hosting check** → **Run workflow** → branch `main`.
3. Read the job summary. The workflow exits non-zero only when SSH fails. Missing tools are reported and the job can still succeed.
4. Put the summary into the table below in a follow-up commit.

## Section 2.2

| Check | Result |
|---|---|
| Plan name (StartUp, GrowBig, GoGeek, or Cloud) | GoGeek, reported by BrandWorx Dev, distrosku.com, Oct 23 2026; not yet verified for this site |
| PHP versions this account can run | `reserveworx:hosting-check` prints `php_binary` for the running binary and for `/usr/bin/php`, `/usr/bin/php8.2` through `php8.5`, `/usr/local/bin/php`, and `/usr/local/php74` through `php86` (that file or `bin/php`) when the file exists and is executable. The running binary uses `PHP_VERSION` and is not started again. Another binary uses the first `x.y.z` from `php -v`, or `version=unread`. A path outside that list is left out, and the `php -v` text is not printed. The October 23 report was CLI `/usr/bin/php` and `/usr/local/bin/php` at 8.2.35, and CGI binaries at `/usr/local/php74`, `php82`, `php83`, `php84`, `php85`, and `php86`. Those CGI binaries reject `php -r`. Deploy run 40080763617 printed `php_version=8.2.35` and did not print `php_binary`. That command has not been run again on this account |
| `memory_limit` | The artisan process printed `memory_limit=768M` on October 24, 2026. The October 23 `php82` CGI probe reported 128M. The per-site PHP selection in Site Tools was not read |
| `max_execution_time` | The artisan process printed `max_execution_time=0` on October 24, 2026. The October 23 `php82` CGI probe printed an empty value. The web limit is still unverified |
| PHP extensions | `reserveworx:hosting-check` prints `php_extension` for bcmath, ctype, curl, dom, fileinfo, gd, intl, mbstring, openssl, pdo_mysql, tokenizer, xml, and zip, then `php_extensions=ok` or `missing`. A missing extension does not fail the check. The October 23 CLI list included those extensions. The October 24 log did not print `php_extension`. That command has not been run again on this account |
| MySQL or MariaDB version | The application connection printed `db_version=8.4.11-11` on October 24, 2026. The October 23 SSH client was MySQL 8.4.10 and could not log in (error 1045, using password NO). There is no `~/.my.cnf` |
| InnoDB, transactions, and `SELECT … FOR UPDATE` | 57 application tables, all `engine=InnoDB`. `for_update=ok`. `transaction_rollback=ok`. Measured October 24, 2026 by `reserveworx:hosting-check` |
| Minimum cron interval | `reserveworx:hosting-check` reads the user crontab. `host_crontab` is `read`, `empty`, or `unavailable`. Each `schedule:run` line prints its five-field expression, or a known `@` alias, and the shortest gap in minutes. A line that is not that kind of schedule is `unparsed`, and then the interval is `unparsed`. The command text, a path, and an assignment are not printed. The October 24, 2026 run printed `host_crontab=not read` and has not been repeated on this account |
| SSH | Opened October 23, 2026. Host key fingerprint `SHA256:yhkspJG+x6V3zEByUKDI5GH/e4+imCN9T46wst5892A`. See the install section below |
| Git on the server | git 2.56.0. Deploys do not use it |
| Composer on the server | Composer 2.4.3. Deploys still build `vendor` on the runner |
| Process limits | This session's `ulimit`: 80 processes, 5060 open files, about 2 GB max memory size. The GoGeek inode, CPU-second, and execution caps remain a report, not a measurement |
| Long-running processes | Assumed **not** allowed. No workers and no WebSockets. Queue work is a short cron process |
| Custom domains and SSL for `app.reserveworx.com` | Installed October 23, 2026. `public_html` is a real directory whose `index.php` boots `../laravel/current`. HTTPS enforcement in Site Tools was not changed. `reserveworx:hosting-check` prints `custom_domains=not_measured` and `app_url_https` as `yes` or `no` from the scheme of `APP_URL` only. The host is not printed. Anything else is `unparsed`. The command does not add a domain or open a TLS connection. The October 24, 2026 log did not include these lines |
| Wildcard subdomains and wildcard SSL | `reserveworx:hosting-check` prints `wildcard_subdomains=not_measured` and `wildcard_ssl=not_measured`. It does not query DNS or request a certificate. The October 23 install did not measure either one. The October 24, 2026 log did not include these lines |
| Outbound HTTPS to Stripe and HighLevel | Both returned HTTP 404 with `ssl_verify_result=0` (the TLS certificate verified). A 404 on the bare host is not an API call |
| Inbound webhook WAF or rate limits | Not observable from SSH. An external curl that receives HTTP 202 is SiteGround's bot check, not proof the application answered |

## Deploy hosting check, October 24, 2026

[Deploy run 40080763617](https://github.com/mymediaworx/reserveworx/actions/runs/40080763617) succeeded. The Hosting check step logged `ssh_handshake=0`, then printed this. No secret is included.

```text
db_version=8.4.11-11
table=allocations engine=InnoDB
table=assets engine=InnoDB
table=audit_logs engine=InnoDB
table=availability_schedules engine=InnoDB
table=blackouts engine=InnoDB
table=booking_line_items engine=InnoDB
table=bookings engine=InnoDB
table=cache engine=InnoDB
table=cache_locks engine=InnoDB
table=capacity_ledger engine=InnoDB
table=customers engine=InnoDB
table=departures engine=InnoDB
table=disclosure_versions engine=InnoDB
table=domains engine=InnoDB
table=failed_jobs engine=InnoDB
table=holds engine=InnoDB
table=incidents engine=InnoDB
table=integration_outbox engine=InnoDB
table=job_batches engine=InnoDB
table=jobs engine=InnoDB
table=locations engine=InnoDB
table=maintenance_records engine=InnoDB
table=maintenance_windows engine=InnoDB
table=memberships engine=InnoDB
table=migrations engine=InnoDB
table=notification_hooks engine=InnoDB
table=password_reset_tokens engine=InnoDB
table=payments engine=InnoDB
table=permissions engine=InnoDB
table=personal_access_tokens engine=InnoDB
table=platform_administrators engine=InnoDB
table=platform_fee_refunds engine=InnoDB
table=platform_fees engine=InnoDB
table=pools engine=InnoDB
table=price_rules engine=InnoDB
table=pricing_snapshots engine=InnoDB
table=promo_codes engine=InnoDB
table=refunds engine=InnoDB
table=rental_checkouts engine=InnoDB
table=report_summaries engine=InnoDB
table=resource_categories engine=InnoDB
table=resource_requirements engine=InnoDB
table=role_permission engine=InnoDB
table=roles engine=InnoDB
table=service_durations engine=InnoDB
table=services engine=InnoDB
table=sessions engine=InnoDB
table=stripe_accounts engine=InnoDB
table=tenant_data_exports engine=InnoDB
table=tenant_settings engine=InnoDB
table=tenants engine=InnoDB
table=users engine=InnoDB
table=waitlist_entries engine=InnoDB
table=waiver_signatures engine=InnoDB
table=waiver_versions engine=InnoDB
table=webhook_events engine=InnoDB
table=widget_tokens engine=InnoDB
app_tables=57
app_tables_innodb=57
innodb=yes
for_update=ok
transaction_rollback=ok
php_version=8.2.35
max_execution_time=0
memory_limit=768M
queue_connection=database
queue_work_max_time=20
schedule=* * * * * queue:work database --stop-when-empty --tries=3 --max-time=20 withoutOverlapping=yes
schedule=* * * * * holds:purge-expired withoutOverlapping=yes
schedule=* * * * * integrations:dispatch withoutOverlapping=yes
schedule=* * * * * rentals:flag-overdue withoutOverlapping=yes
schedule=0 * * * * reports:build-summaries withoutOverlapping=yes
cron_command=schedule:run
host_crontab=not read
```

## Public background, not this account

These are published SiteGround statements. They are not measurements of `app.reserveworx.com`.

- Managed PHP default was documented as 8.2 on May 30, 2024. A security note on October 22, 2026 lists patched builds of PHP 8.5.12, 8.4.27, 8.3.36, and 8.2.35, and discussion still mentions PHP 7.4 being offered. An affiliate features page that claims PHP 8.6 was not used as a source.
- A knowledge-base article dated November 30, 2021 says shared `memory_limit` is 768 MB. The artisan process printed `memory_limit=768M` on October 24, 2026. The October 23 `php82` CGI probe reported 128M, and the per-site selection in Site Tools was not read.
- Fair-use guidance for shared StartUp, GrowBig, and GoGeek says at least 30 minutes between cron runs. If this account is on one of those plans and the limit holds, a Cloud plan or a small VPS is the simplest upgrade. Do not migrate without approval.
- A Laravel knowledge-base article dated August 7, 2025 says Composer is preinstalled and SSH is available. Ultrafast PHP is not on StartUp.
- The hosting check is still required. Public pages do not prove what this account can select.

## Reported layout

Verified October 23, 2026. The home directory is the account home. `www/reserveworx.com` is also present and was not modified. `~/reserveworx` does not exist. The Laravel tree has not been created yet.

```text
/home/customer/www/<domain>/public_html
/home/customer/www/<domain>/laravel
```

For this application the domain is `app.reserveworx.com`. Deploy writes only there. `public_html` is a real directory. See [`DEPLOY.md`](DEPLOY.md).

## Queue budget

Same report: GoGeek allows 4,000 CPU-seconds and 4,000 PHP executions each hour, and cron can run every minute. The default worker is 20 seconds, so 60 runs use 1,200 CPU-seconds and 120 executions (cron plus worker). Workspace sweeps share one more 20-second window and continue on the next run. A minute can use about 40 CPU-seconds. The rest of the hour stays available for web requests. Details are in [`DEPLOY.md`](DEPLOY.md).

Hold expiry, when the booking engine exists, must ignore expired holds at read time.

## Application target

Laravel 12. The deploy PHP binary is 8.2.35, with `memory_limit=768M` and `max_execution_time=0`. The application database reports `8.4.11-11`, and all 57 application tables are InnoDB. The web `max_execution_time` is still unread. The hosting check can read the user crontab. The October 24, 2026 log printed `host_crontab=not read` and has not been repeated on this account.

## First install, October 23, 2026

SSH works. Actions run [39995571872](https://github.com/mymediaworx/reserveworx/actions/runs/39995571872) collected the table above. `SG_SSH_KEY` is an encrypted OpenSSH private key and `SG_SSH_PASSPHRASE` unlocks it. The first connection attempt returned `Permission denied (publickey)` because `IdentitiesOnly` ignored the agent. The script fix is in pull request #6.

SSH could not create the database (error 1045, using password NO). The product owner created it in Site Tools for `reserveworx.com`, which owns the `app.reserveworx.com` subdomain, and stored `SG_DB_DATABASE`, `SG_DB_USERNAME`, and `SG_DB_PASSWORD`. Actions run [40000689467](https://github.com/mymediaworx/reserveworx/actions/runs/40000689467) then wrote `laravel/shared/.env`, migrated the empty database, and seeded two demo tenants. From outside SiteGround, `/up` returned HTTP 200 with “Application up”, and `/` returned HTTP 200 with the ReserveWorx welcome page. Demo passwords are only in `laravel/shared/demo-logins.txt` on the server.

Demo owners, with passwords only in the server file above:

| Workspace | Fee payer | Email | Hostname |
|---|---|---|---|
| Harbor Demo | `customer_paid` | `harbor-owner@demo.reserveworx.test` | `harbor-demo.reserveworx.test` |
| Inland Demo | `operator_absorbed` | `inland-owner@demo.reserveworx.test` | `inland-demo.reserveworx.test` |

Those hostnames are not public DNS. They exist so a request with that `Host` header resolves the tenant.

Still only in Site Tools, and not done by the deploy script:

1. Security → HTTPS Enforce for `app.reserveworx.com` only.
2. Cron, every minute: `/usr/local/bin/php /home/customer/www/app.reserveworx.com/laravel/current/artisan schedule:run`
3. If a stale page appears, Speed → Caching → Dynamic Cache, flush `app.reserveworx.com` only. The on-server purge to `127.0.0.1` could not connect.
