# Hosting checklist

Status: **partially verified** on October 23, 2026 by an SSH session from GitHub Actions. Cells that the session did not measure stay marked unverified. No secret values are stored in this file.

The product owner created `app.reserveworx.com` and stored GitHub Actions secrets `SG_HOST`, `SG_USER`, `SG_PORT` (value stated as 18765), `SG_SSH_KEY`, and possibly `SG_SSH_PASSPHRASE`. Those secrets were not read while this repository was built. The workflows that use them are `.github/workflows/hosting-check.yml` and `.github/workflows/deploy.yml`. Run the hosting check from `main` after merge, then replace the "unverified" cells below with the job summary. Do not paste secrets into this file.

## How to collect the facts

1. Merge the foundation pull request into `main`.
2. GitHub → Actions → **Hosting check** → **Run workflow** → branch `main`.
3. Read the job summary. The workflow exits non-zero only when SSH fails. Missing tools are reported and the job can still succeed.
4. Put the summary into the table below in a follow-up commit.

## Section 2.2

| Check | Result |
|---|---|
| Plan name (StartUp, GrowBig, GoGeek, or Cloud) | GoGeek, reported by BrandWorx Dev, distrosku.com, Oct 23 2026; not yet verified for this site |
| PHP versions this account can run | CLI `/usr/bin/php` and `/usr/local/bin/php` are 8.2.35. CGI binaries exist at `/usr/local/php74`, `php82`, `php83`, `php84`, `php85`, and `php86`. The `php82` CGI binary is also 8.2.35. Those CGI binaries reject `php -r` |
| `memory_limit` | CLI 8.2.35 reports 768M. The `php82` CGI binary reports 128M. The per-site PHP selection in Site Tools was not read |
| `max_execution_time` | CLI reports 0. The `php82` CGI probe printed an empty value. The web limit is still unverified |
| PHP extensions | CLI 8.2.35 includes bcmath, ctype, curl, dom, fileinfo, gd, intl, mbstring, openssl, pdo_mysql, tokenizer, xml, and zip |
| MySQL or MariaDB version | Client is MySQL 8.4.10. The server version is unverified. Login returned error 1045, using password NO. There is no `~/.my.cnf` |
| InnoDB, transactions, and `SELECT … FOR UPDATE` | Unverified on the host. CI covers the row lock on both engines |
| Minimum cron interval | `crontab` is not available over SSH. The every-minute Site Tools schedule remains a report, not a measurement |
| SSH | Opened October 23, 2026. Host key fingerprint `SHA256:yhkspJG+x6V3zEByUKDI5GH/e4+imCN9T46wst5892A`. See the install section below |
| Git on the server | git 2.56.0. Deploys do not use it |
| Composer on the server | Composer 2.4.3. Deploys still build `vendor` on the runner |
| Process limits | This session's `ulimit`: 80 processes, 5060 open files, about 2 GB max memory size. The GoGeek inode, CPU-second, and execution caps remain a report, not a measurement |
| Long-running processes | Assumed **not** allowed. No workers and no WebSockets. Queue work is a short cron process |
| Custom domains and SSL for `app.reserveworx.com` | Installed October 23, 2026. `public_html` is a real directory whose `index.php` boots `../laravel/current`. HTTPS enforcement in Site Tools was not changed |
| Wildcard subdomains and wildcard SSL | Unverified |
| Outbound HTTPS to Stripe and HighLevel | Both returned HTTP 404 with `ssl_verify_result=0` (the TLS certificate verified). A 404 on the bare host is not an API call |
| Inbound webhook WAF or rate limits | Not observable from SSH. An external curl that receives HTTP 202 is SiteGround's bot check, not proof the application answered |

## Public background, not this account

These are published SiteGround statements. They are not measurements of `app.reserveworx.com`.

- Managed PHP default was documented as 8.2 on May 30, 2024. A security note on October 22, 2026 lists patched builds of PHP 8.5.12, 8.4.27, 8.3.36, and 8.2.35, and discussion still mentions PHP 7.4 being offered. An affiliate features page that claims PHP 8.6 was not used as a source.
- A knowledge-base article dated November 30, 2021 says shared `memory_limit` is 768 MB. Treat that number as stale until the check prints `ini_get`.
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

Same report: GoGeek allows 4,000 CPU-seconds and 4,000 PHP executions each hour, and cron can run every minute. The default worker is 20 seconds, so 60 runs use 1,200 CPU-seconds and 120 executions (cron plus worker). The rest of the hour stays available for web requests. Details are in [`DEPLOY.md`](DEPLOY.md).

Hold expiry, when the booking engine exists, must ignore expired holds at read time.

## Application target

Laravel 12 on PHP 8.2.35. The server MySQL version, the web `max_execution_time`, and InnoDB on this host stay unverified until a database login exists.

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
