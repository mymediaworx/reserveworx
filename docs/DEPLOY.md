# Deploy

Production target: `app.reserveworx.com` on SiteGround. `.github/workflows/deploy.yml` runs on every push to `main`, and it can still be started by hand. A push deploys `main` and runs migrations. The workflow always checks out `main`. It does not deploy the branch that happens to contain the workflow file.

The layout below was reported by BrandWorx Dev for distrosku.com on October 23, 2026, and is not yet verified for this site. The script writes the application under `$HOME/www/app.reserveworx.com`. The only other site folder it writes is an existing `$HOME/www/harbor.reserveworx.com/public_html`, and only `index.php` and `.htaccess` there. It does not create that site and it does not write under `www/reserveworx.com`.

```text
/home/customer/www/app.reserveworx.com/public_html/index.php  boots ../laravel/current
/home/customer/www/app.reserveworx.com/laravel/releases/<sha>/
/home/customer/www/app.reserveworx.com/laravel/current
/home/customer/www/app.reserveworx.com/laravel/previous
/home/customer/www/app.reserveworx.com/laravel/shared/.env
/home/customer/www/app.reserveworx.com/laravel/shared/storage
```

`$HOME` is the SSH account home. On the reported account that home is `/home/customer`. The script uses `$HOME`, not a hardcoded customer directory.

Do not run this workflow until:

1. The pull request is merged, so `main` contains the workflow.
2. **Hosting check** has been read.
3. The Site Tools site for `app.reserveworx.com` exists, so `$HOME/www/app.reserveworx.com` is already there.
4. `$HOME/www/app.reserveworx.com/laravel/shared/.env` exists on the server, copied from `.env.example` and filled in on the server. It is never uploaded from the repository.
5. HTTPS Enforce is turned on for `app.reserveworx.com` only (Site Tools → Security → HTTPS Enforce). It is per subdomain. Do not change other sites.

## What the workflow does

`action=deploy`

1. The runner uses PHP 8.2 and `composer install --no-dev`. Composer on the server is unknown and is not used. `composer.json` sets `platform.php` to `8.2.35`, the managed PHP version reported for the other BrandWorx site.
2. The runner builds one `.tar.gz`. The archive is one inode on the server until it is deleted.
3. Excluded from the archive: `.git`, `.env`, `.env.*`, `node_modules`, `tests`, and the runtime storage directories. `vendor` is inside the archive once. It is not copied anywhere else, and the server does not run Composer.
4. The archive is uploaded only to `$HOME/www/app.reserveworx.com/laravel/incoming/<sha>.tar.gz`. If that domain directory does not exist, the job stops. It does not create another site.
5. Before extract, the previous rollback directory is deleted so disk holds the live tree plus the new tree, not a third copy. The archive is deleted as soon as extract finishes.
6. Shared storage and `.env` live in `laravel/shared` and are symlinked into the release. They are never overwritten from the archive.
7. After the new release is current, every release directory other than `current` and `previous` is deleted. Steady state is two trees: the live release and one rollback release. The previous release stays on disk so action `rollback` can switch the code back. The database dump does not delete either tree. Each tree contains the `vendor` directory built for that commit. There is no third vendor directory.
8. CLI PHP must be 8.2 or newer. The script prefers `/usr/local/php82/bin/php`, matching the reported managed PHP 8.2.35.
9. `config:cache`, `route:cache`, and `view:cache` run only when shared `.env` exists.
10. `public_html` stays a real directory. Nginx on this account returns 403 when the document root itself is a symlink. `index.php` there boots `../laravel/current`. A placeholder directory (`index.html`, `index.php`, `cgi-bin`, `.well-known`, `default.html`, any case) is moved aside inside the same domain folder first. Any other files leave `public_html` unchanged and fail the job. A listing of that directory does not use `/dev/fd`, which this host does not provide.
11. Dynamic Cache: the script sends `PURGE` to `http://127.0.0.1/` with `Host: app.reserveworx.com` only. That request is what operators report for nginx; it is not the method in SiteGround's knowledge base. The knowledge base (updated January 29, 2025) says WordPress uses `wp sg purge` and every other application is flushed in Site Tools → Speed → Caching → Dynamic Cache. Success here is a body that contains `Successful purge`. HTTP 202 is not a successful purge. If the purge is not confirmed, flush this domain in Site Tools and do not flush other sites.
12. Health check requests `https://app.reserveworx.com/up` with `Cache-Control: no-cache` and the `wpSGCacheBypass=1` cookie documented in that same knowledge base. Success is HTTP 200 and a body that contains `Application up`. HTTP 202 is the SiteGround bot check and is not proof the application is up. A failed check swaps `current` back to the previous release once and stops. It does not request localhost, and it does not run `migrate:rollback`. The cache purge to `127.0.0.1` is not that check.
13. Before anything is extracted, the script checks that `$HOME/www/harbor.reserveworx.com/public_html` is a real directory and that its resolved path is not under `www/reserveworx.com`. If that directory is missing, the job stops and the app document root is not changed. After the app health check, the script writes `index.php` and `.htaccess` into that harbor directory. `index.php` boots `$HOME/www/app.reserveworx.com/laravel/current`. It then runs `php artisan reserveworx:attach-verified-domain harbor-demo harbor.reserveworx.com`, requests `https://app.reserveworx.com/platform`, requests `https://harbor.reserveworx.com/`, and requests `https://harbor.reserveworx.com/staff/login`. Platform success is HTTP 200 with `Workspaces`, or a redirect to `/platform/login`. Harbor `/` success is HTTP 200 and a body that contains `Harbor Demo bookings` and `<h1>Harbor Demo</h1>`. Harbor staff sign-in success is HTTP 200 and a body that contains `Team sign in`. A failure of either harbor check stops the job and does not roll the release back. The same deploy runs `php artisan reserveworx:grant-platform-admin michael@brandworx.ai`. If that user is missing, the password is appended to `laravel/shared/platform-admin.txt` (mode 600) and is not printed.

`action=rollback`

Swaps the `current` and `previous` symlinks, prunes anything else, attempts the same cache purge, and runs the same health check. **`migrate:rollback` is not run.** Schema changes are not reversed by a code rollback.

Concurrency group `production-deploy` does not cancel an in-progress deploy.

## Migrations

Default is off. `php artisan migrate --force` runs only when all of these are true:

- action is `deploy`
- **Run migrations** is true
- shared `.env` exists
- this run's workflow step has written a verified database dump

That dump is the required backup. Site Tools backups are not. The step runs on the server before the release archive is uploaded and before `public_html` changes. It reads `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` from `laravel/shared/.env` inside PHP. The password is not passed on the command line and is not printed. The client defaults file is mode 600 and is deleted before the dump is accepted. The gzip is written to `$HOME/www/app.reserveworx.com/laravel/shared/backups/<timestamp>.sql.gz`. `<timestamp>` is UTC `YYYYMMDDTHHMMSSZ`. The file is mode 600 and the directory is mode 700. That path is not `public_html` and is not `www/reserveworx.com`. The log records `backup_path`, `backup_bytes`, and `gunzip_test=ok`. The two newest dumps are kept. The previous code release stays on disk for rollback.

The step fails the deploy when any of these are true: the dump file is missing, the file is empty, `gunzip -t` fails, the file is not mode 600, or the archive is not a MySQL or MariaDB dump. The activate step checks that same file again before it removes the old rollback slot or changes the document root. Migrations do not run, and the document root does not change, when the check fails. A restore from this gzip has not been drilled (open question 23).

## Cron and the GoGeek budget

Reported by BrandWorx Dev for distrosku.com on October 23, 2026, not yet verified for this site:

- Plan: GoGeek
- Cron: every minute, configured in Site Tools
- 4,000 CPU-seconds per hour
- 4,000 PHP executions per hour
- 600,000 inode hard cap (that account already hit the cap once)

The script does not install a crontab. Add one Site Tools cron job for this site only:

```text
php /home/customer/www/app.reserveworx.com/laravel/current/artisan schedule:run
```

Use the PHP binary the deploy summary printed. The scheduler runs `queue:work database --stop-when-empty --tries=3 --max-time=20`.

| Piece | Count per hour | Notes |
|---|---|---|
| `schedule:run` | 60 executions | One cron per minute. Do not add a second cron for the worker |
| `queue:work` | up to 60 executions | `withoutOverlapping`, stops after 20 seconds |
| Worker CPU | up to 1,200 CPU-seconds | 60 × 20. About 30% of the 4,000 CPU-second cap |
| Left for HTTP | about 2,800 CPU-seconds and about 3,880 executions | Web requests count against both caps |

Do not raise `QUEUE_WORK_MAX_TIME` without recounting that table. Hold expiry, when the booking engine exists, must still ignore expired holds at read time.

## Secrets

Repository secrets used: `SG_HOST`, `SG_USER`, `SG_PORT`, `SG_SSH_KEY`, optional `SG_SSH_PASSPHRASE`. The workflow masks host, user, port, and passphrase. It does not echo the private key. SSH uses `IdentitiesOnly` and `StrictHostKeyChecking=accept-new`.

Application secrets belong only in `laravel/shared/.env`.

## Trigger

GitHub → Actions → **Deploy** → **Run workflow** → branch `main`.

- First deploy: action `deploy`, migrations off. Shared `.env` must exist before the document root moves.
- Later schema changes: action `deploy`, migrations on. The workflow dumps the database first and fails if that dump does not verify.
- Bad release: action `rollback`. The previous release directory is the code rollback. Rollback does not restore the database.
