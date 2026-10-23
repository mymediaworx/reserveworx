# Hosting checklist

Status: **not verified**. This file is the Section 2.2 checklist. It does not claim that the SiteGround account was inspected.

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
| PHP versions this account can run | Managed PHP 8.2.35, same citation. Not yet verified for this site. The app targets that version |
| `memory_limit` | Still unverified until hosting-check runs |
| `max_execution_time` | Still unverified until hosting-check runs |
| PHP extensions | Unverified |
| MySQL or MariaDB version | Exact version still unverified until hosting-check runs. CI runs MySQL 8.0 and MariaDB 11.8, including the row-lock test |
| InnoDB, transactions, and `SELECT … FOR UPDATE` | Unverified on the host. CI covers the row lock on both engines |
| Minimum cron interval | Every minute via Site Tools, same citation. Not yet verified for this site |
| SSH | Product owner says Actions can connect. Not exercised by this change |
| Git on the server | Unverified. Deploys do not need server-side git |
| Composer on the server | Unknown. Deploys build `vendor` on the runner and do not run Composer on the server |
| Process limits | GoGeek caps from the same report: 600,000 inode hard cap (that account already exceeded it once), 4,000 CPU-seconds/hour, 4,000 executions/hour. Not yet verified for this site |
| Long-running processes | Assumed **not** allowed. No workers and no WebSockets. Queue work is a short cron process |
| Custom domains and SSL for `app.reserveworx.com` | Subdomain was created by the product owner. Document root layout below is reported, not verified here. HTTPS must be enforced per subdomain |
| Wildcard subdomains and wildcard SSL | Unverified |
| Outbound HTTPS to Stripe and HighLevel | Unverified. The check records only the HTTP status and TLS verify result |
| Inbound webhook WAF or rate limits | Not observable from SSH. An external curl that receives HTTP 202 is SiteGround's bot check, not proof the application answered |

## Public background, not this account

These are published SiteGround statements. They are not measurements of `app.reserveworx.com`.

- Managed PHP default was documented as 8.2 on May 30, 2024. A security note on October 22, 2026 lists patched builds of PHP 8.5.12, 8.4.27, 8.3.36, and 8.2.35, and discussion still mentions PHP 7.4 being offered. An affiliate features page that claims PHP 8.6 was not used as a source.
- A knowledge-base article dated November 30, 2021 says shared `memory_limit` is 768 MB. Treat that number as stale until the check prints `ini_get`.
- Fair-use guidance for shared StartUp, GrowBig, and GoGeek says at least 30 minutes between cron runs. If this account is on one of those plans and the limit holds, a Cloud plan or a small VPS is the simplest upgrade. Do not migrate without approval.
- A Laravel knowledge-base article dated August 7, 2025 says Composer is preinstalled and SSH is available. Ultrafast PHP is not on StartUp.
- The hosting check is still required. Public pages do not prove what this account can select.

## Reported layout

Reported by BrandWorx Dev, distrosku.com, Oct 23 2026; not yet verified for this site.

```text
/home/customer/www/<domain>/public_html
/home/customer/www/<domain>/laravel
```

For this application the domain is `app.reserveworx.com`. Deploy writes only there. `public_html` is a symlink to `../laravel/current/public`. See [`DEPLOY.md`](DEPLOY.md).

## Queue budget

Same report: GoGeek allows 4,000 CPU-seconds and 4,000 PHP executions each hour, and cron can run every minute. The default worker is 20 seconds, so 60 runs use 1,200 CPU-seconds and 120 executions (cron plus worker). The rest of the hour stays available for web requests. Details are in [`DEPLOY.md`](DEPLOY.md).

Hold expiry, when the booking engine exists, must ignore expired holds at read time.

## Application target

Laravel 12 on PHP 8.2.35. `memory_limit`, `max_execution_time`, and the exact database version stay unverified until **Hosting check** runs on this site.
