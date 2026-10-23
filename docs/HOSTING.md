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
| Plan name (StartUp, GrowBig, GoGeek, or Cloud) | Unverified. The SSH report says the plan is not exposed by the session rather than guessing |
| PHP versions this account can run | Unverified |
| `memory_limit` | Unverified |
| `max_execution_time` | Unverified |
| PHP extensions | Unverified |
| MySQL or MariaDB version | Unverified |
| InnoDB, transactions, and `SELECT … FOR UPDATE` | Unverified on the host. CI MySQL 8 exercises a row lock |
| Minimum cron interval | Unverified |
| SSH | Product owner says Actions can connect. Not exercised by this change |
| Git on the server | Unverified. Deploys do not need server-side git |
| Composer on the server | Unverified. Deploys install dependencies on the runner and upload `vendor` |
| Process limits | Unverified |
| Long-running processes | Assumed **not** allowed. No workers and no WebSockets until proven |
| Custom domains and SSL for `app.reserveworx.com` | Subdomain was created by the product owner. Certificate and document root are unverified |
| Wildcard subdomains and wildcard SSL | Unverified |
| Outbound HTTPS to Stripe and HighLevel | Unverified. The check records only the HTTP status and TLS verify result |
| Inbound webhook WAF or rate limits | Not observable from SSH |

## Public background, not this account

These are published SiteGround statements. They are not measurements of `app.reserveworx.com`.

- Managed PHP default was documented as 8.2 on May 30, 2024. A security note on October 22, 2026 lists patched builds of PHP 8.5.12, 8.4.27, 8.3.36, and 8.2.35, and discussion still mentions PHP 7.4 being offered. An affiliate features page that claims PHP 8.6 was not used as a source.
- A knowledge-base article dated November 30, 2021 says shared `memory_limit` is 768 MB. Treat that number as stale until the check prints `ini_get`.
- Fair-use guidance for shared StartUp, GrowBig, and GoGeek says at least 30 minutes between cron runs. If this account is on one of those plans and the limit holds, a Cloud plan or a small VPS is the simplest upgrade. Do not migrate without approval.
- A Laravel knowledge-base article dated August 7, 2025 says Composer is preinstalled and SSH is available. Ultrafast PHP is not on StartUp.
- The hosting check is still required. Public pages do not prove what this account can select.

## Recommendation until the check runs

Keep the database queue, cron-triggered `schedule:run`, and a 50-second `queue:work --max-time`. Hold expiry, when the booking engine exists, must ignore expired holds at read time so a 30-minute cron cannot double-book.

Laravel 13 stays only if the account can run PHP 8.3 or newer. If it cannot, the fallback proposal is Laravel 12. See [`DECISIONS.md`](DECISIONS.md).

Document-root candidates, in order, are:

1. `$HOME/www/app.reserveworx.com/public_html`
2. `$HOME/www/app.reserveworx.com`
3. `$HOME/public_html/app.reserveworx.com`

The deploy script does not invent a fourth path. If none of these exist, it leaves the release in `$HOME/reserveworx/releases/<sha>` and does not claim the site is live.
