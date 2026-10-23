# Deploy

Production target: `app.reserveworx.com` on SiteGround. The supported path is the manual GitHub Actions workflow `.github/workflows/deploy.yml`. It always checks out `main`. It does not deploy the branch that happens to contain the workflow file.

Do not run this workflow until:

1. The pull request is merged, so `main` contains the workflow.
2. **Hosting check** has been read.
3. `$HOME/reserveworx/shared/.env` exists on the server, copied from `.env.example` and filled in on the server. It is never uploaded from the repository.

## What the workflow does

`action=deploy`

1. The runner uses PHP 8.3 and `composer install --no-dev`. The server does not need Composer or GitHub credentials.
2. `rsync` uploads the tree, including `vendor`, to `$HOME/reserveworx/releases/<40-character sha>/`.
3. Excluded from the upload: `.git`, `.env`, `.env.*`, `node_modules`, `tests`, and the runtime storage directories.
4. Shared storage is `$HOME/reserveworx/shared/storage`. The release's `storage` path is a symlink to it. Shared `.env` is a symlink and is never overwritten.
5. `current` points at the new release. The previous target is kept as `previous`.
6. PHP must be 8.3 or newer. A default `php` that is 8.2 is not used. If no suitable binary exists, the document root is not changed.
7. `config:cache`, `route:cache`, and `view:cache` run only when shared `.env` exists.
8. Document root candidates, in order:
   - `$HOME/www/app.reserveworx.com/public_html`
   - `$HOME/www/app.reserveworx.com`
   - `$HOME/public_html/app.reserveworx.com`

   An existing symlink is pointed at `current/public`. An empty directory, or one that contains only `index.html`, `index.php`, `cgi-bin`, `.well-known`, or `default.html`, is moved to `*.pre-reserveworx-<timestamp>` and replaced with that symlink. Any other files leave the document root unchanged and fail the job. If none of the candidates exist, the release stays on disk and the job fails. No other path is invented.

`action=rollback`

Swaps the `current` and `previous` symlinks. Because the document root points at `current/public`, the site follows the swap. **`migrate:rollback` is not run.** Schema changes are not reversed by a code rollback.

Concurrency group `production-deploy` does not cancel an in-progress deploy.

## Migrations

Default is off. `php artisan migrate --force` runs only when all of these are true:

- action is `deploy`
- **Run migrations** is true
- the confirmation field is exactly `I have a backup`
- `$HOME/reserveworx/shared/.env` exists

Anything else skips migrations or fails the job without migrating. There is no production data in this foundation schema yet. Do not point the application at a database that already has customer data and then migrate until a restore drill is written. Foundation migrations create empty tenant tables. They are still a production schema change and stay behind the confirmation phrase.

Take the backup in SiteGround's backup tool before typing the phrase. The workflow does not take a backup for you.

## Cron

The script prints a hint and does not install a crontab, because crontab lines can contain tokens and an automatic install could overwrite existing jobs. After the first successful deploy, add one cron entry in SiteGround that runs every minute the plan allows:

```text
php /home/<account>/reserveworx/current/artisan schedule:run
```

Use the PHP 8.3+ binary the deploy summary printed, not a PHP 8.2 default. The scheduler runs `queue:work database --stop-when-empty --tries=3 --max-time=50`.

## Secrets

Repository secrets used: `SG_HOST`, `SG_USER`, `SG_PORT`, `SG_SSH_KEY`, optional `SG_SSH_PASSPHRASE`. The workflow masks host, user, port, and passphrase. It does not echo the private key. SSH uses `IdentitiesOnly` and `StrictHostKeyChecking=accept-new`. The job summary shows the SHA256 host-key fingerprint on the Actions log stderr, not the host key itself.

Application secrets belong only in the server's shared `.env`.

## Trigger

GitHub → Actions → **Deploy** → **Run workflow** → branch `main`.

- First deploy: action `deploy`, migrations off, confirmation empty. This still requires shared `.env` before the document root moves.
- Later schema changes: action `deploy`, migrations on, confirmation `I have a backup`.
- Bad release: action `rollback`.
