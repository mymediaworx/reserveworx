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

Rollback of code does not undo the migration. When migrations were enabled, the deploy wrote `$HOME/www/app.reserveworx.com/laravel/shared/backups/<timestamp>.sql.gz` before `artisan migrate`. The job log shows `backup_path`, `backup_bytes`, and `gunzip_test=ok`. Restore that file only inside this site's database:

```bash
php artisan reserveworx:restore-backup "$HOME/www/app.reserveworx.com/laravel/shared/backups/<timestamp>.sql.gz" --force
```

The command refuses the file unless it is mode 600, the gzip is a MySQL or MariaDB dump, and the dump names the database in this site's `.env` and no other database. Without `--force` it changes nothing. It does not print the password or the SQL. A path under `public_html` or `www/reserveworx.com` is refused. Then roll the code back to the previous release if the dump matches that release. Do not copy the gzip into `public_html` or into another site. The previous release directory stays on disk for that code rollback.

The replay is covered by the test suite. A restore has not been run on the live SiteGround database (open question 23).

## Tenant is unavailable or a user cannot sign in

- Suspended and terminated tenants return 403 on tenant routes, including `POST /api/v1/login`.
- Unknown hosts and unverified domains return the same 404 text, including staff API sign-in.
- `POST /api/v1/login` on a workspace address requires an active member of that workspace. The same reply is used for a wrong password and for someone who is not on the team. `GET /api/v1/me` on that address lists only that workspace.
- A platform administrator has no tenant API access without a membership.
- Login is limited to 5 attempts per minute per email and IP.
- The last active owner cannot be revoked or demoted. Add another owner first.

## Grant or remove platform access

Platform administrators are not created by registration.

```bash
php artisan reserveworx:grant-platform-admin person@example.com
```

Without `--password-file`, the command does not create a user. The production deploy passes that option for `michael@brandworx.ai` and, when the account is new, appends the password to `laravel/shared/platform-admin.txt`. The password is not printed. Removal is a row delete from `platform_administrators` by someone who already has production database access. That delete is not an API yet.

## Customer record download and removal

An owner or manager opens `/staff/customers` on the workspace host. Search finds this workspace’s customers by name, email, or phone. **Download their record** queues the same private zip as the customer export API. The file stays on the local disk. The page shows a signed link that lasts 30 minutes. **Remove personal details** anonymizes that customer. It is refused until a download file is on disk, unless the person checks the box to remove details anyway. Bookings, payments, and signed waivers stay. How long a signed waiver is kept is still open question 3. Staff, and a member limited to some locations, cannot open the page.

## Queue looks stuck

One Site Tools cron job calls `schedule:run`, which runs `queue:work database --stop-when-empty`. A worker stops after `QUEUE_WORK_MAX_TIME` (default 20 seconds). The reported GoGeek caps are 4,000 CPU-seconds per hour and 4,000 executions per hour. Sixty workers at 20 seconds use 1,200 CPU-seconds. Do not add a second cron and do not raise the max time without recounting that budget in [`DEPLOY.md`](DEPLOY.md).

## Secrets in a log

Audit logging strips values whose keys look like passwords, tokens, secrets, authorization headers, client secrets, SSH keys, or passphrases. If a secret still appears in a GitHub Actions log, revoke it at the source and store a replacement. Do not echo the old value in the revocation note.
