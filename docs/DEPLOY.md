# Deploy

Production target: `app.reserveworx.com` on SiteGround. `.github/workflows/deploy.yml` starts when the **CI** workflow completes for a push to `main` (`workflow_run`), and it can still be started by hand. Its **Wait for CI** job deploys only when that CI run succeeded and its commit is still the tip of `main`. It then checks out that exact commit and runs migrations. A failed or timed-out CI run marks the deploy failed and deploys nothing. A commit that is no longer the tip is skipped, because the newer commit deploys after its own CI passes. A manual run deploys the current tip of `main`. Before October 25, 2026, deploy started on the push itself, in parallel with CI (#129).

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
2a. **Inode guard.** Before the backup and the upload, the job runs `ACTION=preflight`. It counts the whole SSH account with `du --inodes -s -x "$HOME"`, adds the repo variable `RW_INODE_EXTERNAL` (inodes used on the plan outside that home: the main site, mail and other backups, from Site Tools; unset counts as 0), warns at 450,000 and stops the deploy at 540,000 of the 600,000 cap. At or above the warn level it first removes regenerable files (stale archives in `incoming`, compiled views, sessions older than 2 days, file cache, logs older than 7 days, extra dumps) and counts again. The deploy repeats the check before it extracts anything and deletes the uploaded archive when it stops. Rollback and backup never run the guard. If `du --inodes` returns nothing, the guard says `inode_guard=unmeasured` and skips.
2b. **Maintenance mode.** Run the existing Deploy workflow by hand with `action: maintenance`. It deploys nothing, runs no migrations, skips the install, pack, backup and upload steps, never creates, links, prunes or uploads a release, and uses the same single SSH connection. It always prints `maintenance_inodes_before`, a read-only report (resolved laravel path and mount; counts with `find` and `find -L`; `du --inodes` with and without `-x`; every release with and without `vendor`; whether `current` and `previous` are symlinks or real directories; and where `demo-logins.txt` and `platform-admin.txt` are, as path, mode and whether each is under a `public_html` web root, never their contents), and a read-only breakdown from the account home (`find . -xdev -type f | cut -d/ -f2-4 | sort | uniq -c | sort -rn | head -40`, plus an inode total per top-level directory). With `clean: true` it then clears compiled views and file cache in `shared/storage` and in each release's own `storage`, session files older than 2 days (never all sessions, live users stay signed in), logs older than 7 days, stale archives in `incoming`, and database dumps beyond two (`shared/backups` itself stays), moves a secret hand-off file found under `app.reserveworx.com/public_html` into `laravel/shared`, and prints `maintenance_inodes_after`. With `purge_opcache: true` (off by default, maintenance only, independent of `clean`) it also deletes only the contents of `$HOME/.opcache` with `find "$HOME/.opcache" -mindepth 1 -delete` and keeps the folder. It refuses unless the resolved path is exactly `<real home>/.opcache` and a real directory (a symlink is refused, and `find` never follows one out), and prints `opcache_inodes_before`, `opcache_inodes_after`, `opcache_account_inodes_before` and `opcache_account_inodes_after`. PHP rebuilds the cache on demand.
3. Excluded from the archive: `.git`, `.env`, `.env.*`, `node_modules`, `tests`, `.github`, `.cursor`, `docs`, `phpunit.xml`, `phpstan.neon`, `pint.json`, `package.json`, `package-lock.json`, `vite.config.js`, and the runtime storage directories. `docs/changelog.d/` is part of that `docs` exclude, so a changelog fragment is not in the archive. `vendor` is inside the archive once. It is not copied anywhere else, and the server does not run Composer.
4. The archive is uploaded only to `$HOME/www/app.reserveworx.com/laravel/incoming/<sha>.tar.gz`. If that domain directory does not exist, the job stops. It does not create another site.
5. Before extract, the previous rollback directory is deleted so disk holds the live tree plus the new tree, not a third copy. The archive is deleted as soon as extract finishes.
6. Shared storage and `.env` live in `laravel/shared` and are symlinked into the release. They are never overwritten from the archive.
7. Migrations (when requested), the role and permission sync, `config:cache`, `route:cache`, and `view:cache` run from the new release directory **before** `current` moves to it. Until then every request is served by the old release, so new code never runs against the old schema. If any of those steps fails, `current` is not changed and the new release directory is removed (#130). After the new release is current, every release directory other than `current` and `previous` is deleted. Steady state is two trees: the live release and one rollback release. The previous release stays on disk so action `rollback` can switch the code back. The database dump does not delete either tree. Each tree contains the `vendor` directory built for that commit. There is no third vendor directory.
8. CLI PHP must be 8.2 or newer. The script prefers `/usr/local/php82/bin/php`, matching the reported managed PHP 8.2.35.
9. `config:cache`, `route:cache`, and `view:cache` run only when shared `.env` exists. Before `config:cache`, a deploy writes the mail keys in that shared file. When the `MAIL_PASSWORD` secret is present, the keys are `MAIL_MAILER=smtp`, `MAIL_HOST=mail.reserveworx.com`, `MAIL_PORT=465`, `MAIL_SCHEME=smtps`, `MAIL_USERNAME=noreply@reserveworx.com`, `MAIL_FROM_ADDRESS=noreply@reserveworx.com`, `MAIL_FROM_NAME=ReserveWorx`, and `MAIL_PASSWORD` from the secret. The password is not printed. It is sent on the same SSH session as the release. When the secret is empty or missing, `MAIL_MAILER` stays `log`, the log line is `mail_configured=no`, and the deploy continues. The script then runs `php artisan reserveworx:mail-status`. That command prints the mail driver and whether it can deliver. It does not send mail. The password file used for the hand-off is deleted and is never under `public_html` or `www/reserveworx.com`.
10. `public_html` stays a real directory. Nginx on this account returns 403 when the document root itself is a symlink. `index.php` there boots `../laravel/current`. A placeholder directory (`index.html`, `index.php`, `cgi-bin`, `.well-known`, `default.html`, any case) is moved aside inside the same domain folder first. Any other files leave `public_html` unchanged and fail the job. A listing of that directory does not use `/dev/fd`, which this host does not provide.
11. Dynamic Cache: the script sends `PURGE` to `http://127.0.0.1/` with `Host: app.reserveworx.com` only. That request is what operators report for nginx; it is not the method in SiteGround's knowledge base. The knowledge base (updated January 29, 2025) says WordPress uses `wp sg purge` and every other application is flushed in Site Tools → Speed → Caching → Dynamic Cache. Success here is a body that contains `Successful purge`. HTTP 202 is not a successful purge. If the purge is not confirmed, flush this domain in Site Tools and do not flush other sites.
12. Health check requests `https://app.reserveworx.com/up` with `Cache-Control: no-cache` and the `wpSGCacheBypass=1` cookie documented in that same knowledge base. Success is HTTP 200 and a body that contains `Application up`. HTTP 202 is the SiteGround bot check and is not proof the application is up. A failed check swaps `current` back to the previous release once and stops. It does not request localhost, and it does not run `migrate:rollback`. The cache purge to `127.0.0.1` is not that check.
12b. After a healthy deploy (and after a healthy rollback), `inode_cleanup` runs for `www/app.reserveworx.com` only: keeps current plus one rollback, deletes the upload archive, drops `node_modules`/`.composer`/`.cache`/`tests`/`.github`/`.cursor`/`docs` leftovers inside kept releases, prunes stale compiled views (>3h), file sessions (>1d), file cache (>7d), pail (>1d) and logs (>7d, daily channel), removes `public_html.pre-reserveworx-*` leftovers this deploy created, moves any orphan `laravel/backups/*.sql.gz` into `laravel/shared/backups` and keeps at most two dumps there (also enforced on every backup), and logs `du --inodes` before/after plus `df -iP` for the app filesystem. It never touches `www/reserveworx.com`, `.env`, `platform-admin.txt`, `demo-logins.txt`, or shared uploads. A leftover `docs` directory, including `docs/changelog.d/`, is removed here, so changelog fragments add no inodes to a release.
13. Before anything is extracted, the script checks that `$HOME/www/harbor.reserveworx.com/public_html` is a real directory and that its resolved path is not under `www/reserveworx.com`. If that directory is missing, the job stops and the app document root is not changed. After the app health check, the script writes `index.php` and `.htaccess` into that harbor directory. `index.php` boots `$HOME/www/app.reserveworx.com/laravel/current`. It then runs `php artisan reserveworx:attach-verified-domain harbor-demo harbor.reserveworx.com`, requests `https://app.reserveworx.com/platform`, requests `https://harbor.reserveworx.com/`, and requests `https://harbor.reserveworx.com/staff/login`. Platform success is HTTP 200 with `Workspaces`, or a redirect to `/platform/login`. Harbor `/` success is HTTP 200 and a body that contains `Harbor Demo bookings` and `<h1>Harbor Demo</h1>`. Harbor staff sign-in success is HTTP 200 and a body that contains `Team sign in`. A failure of either harbor check stops the job and does not roll the release back. The same deploy runs `php artisan reserveworx:grant-platform-admin michael@brandworx.ai`. If that user is missing, the password is appended to `laravel/shared/platform-admin.txt` (mode 600) and is not printed. It then runs `php artisan db:seed --class=DemoShopSeeder`, which fills only `harbor-demo` and `inland-demo` and leaves every other workspace alone, and `php artisan reserveworx:grant-demo-owner michael@brandworx.ai harbor-demo`. Harbor `/` also has to contain `Single kayak rental`, `Paddleboard rental`, and `Sunset paddle tour`.
14. After those checks, the script runs `php artisan reserveworx:smoke` on the release that is already current. The command uses the database and in-process kernel requests. It does not call the public internet. It prints `key=value` lines for the database, pending migrations, roles and permissions, mail, the queue table, `harbor-demo` and its booking page, `/staff/login`, `/signup` on the app host, and the scheduler last-run timestamp when one is stored. It does not print secrets. A non-zero exit fails the job. It does not swap the release back and it does not run `migrate:rollback`. A missing scheduler timestamp is printed as `scheduler_last_run=not_recorded` and does not by itself fail the command. Rollback does not run this command.

`action=rollback`

Swaps the `current` and `previous` symlinks, prunes anything else, attempts the same cache purge, runs the same health check, then runs the same `inode_cleanup`. **`migrate:rollback` is not run.** Schema changes are not reversed by a code rollback.

A failed deploy deletes every archive left in `incoming`, and a failed upload removes its partial archive. Release pruning only deletes real directories named with a 40-character sha under `laravel/releases`, and does nothing when `current` cannot be resolved to a release there.

Concurrency group `production-deploy` does not cancel an in-progress deploy.

## Migrations

Default is off. `php artisan migrate --force` runs only when all of these are true:

- action is `deploy`
- **Run migrations** is true
- shared `.env` exists
- this run's workflow step has written a verified database dump

That dump is the required backup. Site Tools backups are not. The step runs on the server before the release archive is uploaded and before `public_html` changes. It reads `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` from `laravel/shared/.env` inside PHP. The password is not passed on the command line and is not printed. The client defaults file is mode 600 and is deleted before the dump is accepted. The gzip is written to `$HOME/www/app.reserveworx.com/laravel/shared/backups/<timestamp>.sql.gz`. `<timestamp>` is UTC `YYYYMMDDTHHMMSSZ`. The file is mode 600 and the directory is mode 700. That path is not `public_html` and is not `www/reserveworx.com`. The log records `backup_path`, `backup_bytes`, and `gunzip_test=ok`. The two newest dumps are kept. The previous code release stays on disk for rollback.

The step fails the deploy when any of these are true: the dump file is missing, the file is empty, `gunzip -t` fails, the file is not mode 600, or the archive is not a MySQL or MariaDB dump. The activate step checks that same file again before it removes the old rollback slot or changes the document root. Migrations do not run, and the document root does not change, when the check fails. Restore that gzip with `php artisan reserveworx:restore-backup <path> --force` from the release that matches it. The command is covered by the test suite. A restore has not been run on the live SiteGround database (open question 23).

## Cron and the GoGeek budget

Reported by BrandWorx Dev for distrosku.com on October 23, 2026, not yet verified for this site:

- Plan: GoGeek
- Cron: every minute, configured in Site Tools
- 4,000 CPU-seconds per hour
- 4,000 PHP executions per hour
- 600,000 inode hard cap (that account already hit the cap once). The app itself is about 20,000; the deploy guard and `RW_INODE_EXTERNAL` exist because the rest of the plan counts too. The scheduler also runs `php artisan host:prune-files` daily at 03:17 (sessions >2d, views >7d, file cache >7d, logs >7d). Production logs use the `daily` channel with `LOG_DAILY_DAYS=7`; deploy writes both keys into the shared `.env` without printing it.

The script does not install a crontab. Add one Site Tools cron job for this site only:

```text
php /home/customer/www/app.reserveworx.com/laravel/current/artisan schedule:run
```

Use the PHP binary the deploy summary printed. The scheduler runs `queue:work database --stop-when-empty --tries=3 --max-time=20`.

Every minute the schedule also writes a heartbeat (`reserveworx:schedule:last-run` in the cache). `reserveworx:smoke` prints `scheduler_last_run` and `scheduler=ok`, `stale` (no run in 10 minutes), or `not_seen`. The hosting check prints `scheduler_heartbeat` and `scheduler`. `/platform` shows a warning to platform admins until the heartbeat is fresh. A missing cron does not fail the deploy, but without it staff cancellations do not finish or refund, imports wait, and reminders and overdue flags stop (#131).

| Piece | Count per hour | Notes |
|---|---|---|
| `schedule:run` | 60 executions | One cron per minute. Do not add a second cron for the worker |
| `queue:work` | up to 60 executions | `withoutOverlapping`, stops after 20 seconds |
| Workspace sweeps | up to 60 executions | Holds, undo, integrations, and overdue rentals share one 20-second window after the worker. Reminders, feedback, and summaries use that same window when they are due, then continue next run |
| Worker plus sweeps | up to about 2,400 CPU-seconds | 60 × (20 + 20). About 60% of the reported 4,000 CPU-second cap. That cap was reported for another site and is not measured here |
| Left for HTTP | about 1,600 CPU-seconds | Web requests count against both caps |

Do not raise `QUEUE_WORK_MAX_TIME`. A value outside 10–20 is clamped, and `reserveworx:limits` fails. Hold expiry still ignores expired holds at read time.

## Upgrade

An upgrade replaces the live release with a newer commit of this same application. It is not a first install. Reading this procedure does not run a deploy and does not write a booking, a price, a payment, a tax, a fee, or a refund.

A first install is the local setup in [`README.md`](README.md), then the first deploy in [Trigger](#trigger) with migrations off. Backup is the private dump written before migrations. Recovery is action `rollback` for the code, and `php artisan reserveworx:restore-backup` for that dump. A restore has not been run on the live SiteGround database. Platform administrators read this same procedure at `/platform/upgrade`.

### Wait for CI

Deploy starts after CI passes for that commit, and only while that commit is still the tip of main. A failed CI run deploys nothing.

### The server does not run Composer

The runner installs dependencies. The server does not run Composer.

### Back up before migrations

Migrations run only after a verified private dump of this database. The file is mode 600 under laravel/shared/backups. If that dump is missing or does not verify, migrations do not run and the live release stays.

### Cache the new release first

Migrations, the role sync, and the config, route, and view caches run on the new release before it becomes current. If those steps fail, the live release stays.

### Keep one rollback release

The previous release stays on disk. Rollback swaps that code back and does not undo migrations.

### Restore the database

reserveworx:restore-backup replays one private dump into this site's database. The file has to be mode 600 and a MySQL or MariaDB dump that names this database and no other. Nothing is written until --force is passed. A path under public_html or www/reserveworx.com is refused. The command does not print the password or the SQL. This page does not run a restore. A restore has not been run on the live SiteGround database.

### Check the public health URL

The health check requests https://app.reserveworx.com/up. A failed check swaps the code back once and does not roll migrations back.

### Point cron at the current release

Cron keeps calling laravel/current so the next minute uses the new release. Do not add a second cron.

### Smoke and readiness

After a healthy upgrade, reserveworx:smoke runs. Open /platform/readiness for the same local checks. Neither one writes a price, a tax, a fee, or a refund.

### First install

A first install is the local setup in README.md, then the first deploy with migrations off. Shared .env has to exist on the server before the document root moves. This page does not run that install.

### Start a deploy

Open GitHub Actions, choose Deploy, and run the workflow on branch main. A later upgrade uses action deploy with migrations on. The workflow dumps the database first and fails if that dump does not verify. A bad release uses action rollback. That swaps the previous release and does not restore the database. This page does not run a deploy.

## Secrets

Repository secrets used: `SG_HOST`, `SG_USER`, `SG_PORT`, `SG_SSH_KEY`, optional `SG_SSH_PASSPHRASE`, and optional `MAIL_PASSWORD`. The workflow masks host, user, port, passphrase, and the mail password. It does not echo the private key or the mail password. An empty `MAIL_PASSWORD` leaves the log mailer in place and does not fail the job. SSH uses `IdentitiesOnly` and `StrictHostKeyChecking=accept-new`.

A push deploy used to open eight connections to the SSH port. Each of the four remote commands (`backup`, create the incoming directory, upload the archive, activate) ran `ssh-keyscan` and then a new SSH handshake. SiteGround resets the next key exchange once that burst is high enough, which is why activation died after the backup and the upload had already succeeded. Retrying the handshake added more connections and was reset the same way.

The wrapper now opens one handshake for the job and reuses it for the backup, the upload, and activation (`ControlMaster` and `ControlPersist`). Later commands log `ssh_handshake=0`. There is no `ssh-keyscan` probe and no handshake retry. The master is closed when activation finishes, and also when an earlier SSH step fails. The host key is learned on that one connection.

Application secrets belong only in `laravel/shared/.env`.

## Trigger

GitHub → Actions → **Deploy** → **Run workflow** → branch `main`.

- First deploy: action `deploy`, migrations off. Shared `.env` must exist before the document root moves.
- Later schema changes: action `deploy`, migrations on. The workflow dumps the database first and fails if that dump does not verify.
- Bad release: action `rollback`. The previous release directory is the code rollback. Rollback does not restore the database.
