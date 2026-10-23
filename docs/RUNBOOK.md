# Runbook

Operational notes for the foundation release. Payments, bookings, and GoHighLevel are not in production yet, so those incidents are not covered here.

## Hosting check failed

The job is red only when SSH did not complete. Open the run log and look for one of these messages. They do not include the key.

- `SG_HOST is required` or the matching message for user, port, or key: the repository secret is missing.
- `The SiteGround SSH key is encrypted`: set `SG_SSH_PASSPHRASE`, or replace the secret with an unencrypted key.
- `Could not unlock the SiteGround SSH key`: the passphrase does not match. Do not paste the key into an issue.
- A timeout: confirm port 18765 from SiteGround's SSH screen, and that the GitHub Actions egress range is allowed. Do not switch the workflow to `StrictHostKeyChecking=no`.

A green run with `not found` lines is still a successful check. Record those lines in [`HOSTING.md`](HOSTING.md).

## Deploy failed before the document root moved

The release may exist under `$HOME/www/app.reserveworx.com/laravel/releases/<sha>`. The public site is unchanged if the summary says `document_root=not changed` or `document_root=left unchanged`. Do not rename folders that belong to other domains.

Typical causes:

- Shared `.env` is missing. Create `$HOME/www/app.reserveworx.com/laravel/shared/.env` on the server, then rerun deploy.
- No PHP 8.2+ binary. Stop and read the hosting report. The reported managed PHP is 8.2.35.
- `public_html` has unexpected files. Move them by hand inside this domain only. Do not widen the placeholder allow-list.
- `$HOME/www/app.reserveworx.com` does not exist. Create that site in Site Tools, then rerun. Do not point the script at another domain.

## Deploy succeeded and the site errors

1. Run **Deploy** with action `rollback`. That swaps `current` and `previous` and does not change the database.
2. If this was the first release, there is no previous symlink. Move `public_html.pre-reserveworx-<timestamp>` back to `public_html` inside `app.reserveworx.com` only.
3. Read `$HOME/www/app.reserveworx.com/laravel/shared/storage/logs/laravel.log` on the server. Do not copy `.env` into the ticket.
4. If the summary says `health_http=202`, that is SiteGround's bot check. It is not proof the app is up or down. Open the site in a browser, or curl with a normal browser user agent, before treating the deploy as failed.
5. If the summary says `dynamic_cache=not confirmed`, flush **app.reserveworx.com** in Site Tools → Speed → Caching → Dynamic Cache. Do not flush other sites. SiteGround's knowledge base (January 29, 2025) documents that screen for non-WordPress apps. `wp sg purge` is for WordPress.

## A migration ran and the app is broken

Rollback of code does not undo the migration. Restore the SiteGround database backup taken before the confirmation phrase was typed. Then roll the code back if the backup matches the previous release.

There is no tested restore drill yet (open question 23). Do not enable migrations against a database that already has customer data until that drill exists.

## Tenant is unavailable or a user cannot sign in

- Suspended and terminated tenants return 403 on tenant routes.
- Unknown hosts and unverified domains return the same 404 text.
- A platform administrator has no tenant API access without a membership.
- Login is limited to 5 attempts per minute per email and IP.
- The last active owner cannot be revoked or demoted. Add another owner first.

## Grant or remove platform access

Platform administrators are not created by registration.

```bash
php artisan reserveworx:grant-platform-admin person@example.com
```

The command does not create a user. The user must already exist. Removal is a row delete from `platform_administrators` by someone who already has production database access. That delete is not an API yet.

## Queue looks stuck

One Site Tools cron job calls `schedule:run`, which runs `queue:work database --stop-when-empty`. A worker stops after `QUEUE_WORK_MAX_TIME` (default 20 seconds). The reported GoGeek caps are 4,000 CPU-seconds per hour and 4,000 executions per hour. Sixty workers at 20 seconds use 1,200 CPU-seconds. Do not add a second cron and do not raise the max time without recounting that budget in [`DEPLOY.md`](DEPLOY.md).

## Secrets in a log

Audit logging strips values whose keys look like passwords, tokens, secrets, authorization headers, client secrets, SSH keys, or passphrases. If a secret still appears in a GitHub Actions log, revoke it at the source and store a replacement. Do not echo the old value in the revocation note.
