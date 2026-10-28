# Cutover: SiteGround to DigitalOcean

Status: draft runbook. Nothing in it has been run against production. The target is `reserveworx-prod-1` (DigitalOcean, atl1, Ubuntu 24.04). SiteGround stays live and untouched until the verification step passes, and stays available as the rollback target for at least 7 days afterwards.

Decisions that need the product owner (Michael) are marked **[Michael]**. No step that changes DNS, moves live data or spends money runs without his written yes.

## 0. Server layout (already provisioned)

- PHP 8.3-fpm, nginx, MySQL 8, Composer, Node LTS, Supervisor (2 queue workers), certbot with the nginx plugin, ufw 22/80/443.
- Releases live in `/var/www/reserveworx/releases/<sha>`. `current` points at the live release and `previous` at the one rollback. Environment is in `/var/www/reserveworx/shared/.env` and storage in `/var/www/reserveworx/shared/storage`, both on the server only.
- Deploys use `.github/workflows/deploy-digitalocean.yml` (manual dispatch) and `scripts/remote-deploy-digitalocean.sh`. Secrets: `DO_HOST`, `DO_USER`, `DO_SSH_KEY`.
- The Laravel scheduler is **off** (no cron entry) until step 5. Queue workers run, with `MAIL_MAILER=log`, until step 5.

## 1. Rehearsal (before any freeze)

Run the whole sequence once against a copy, on the droplet only, at its `sslip.io` hostname (`129-212-180-114.sslip.io`).

1. Deploy current `main` with `run_migrations` off, then on once the import below is in place.
2. Import a recent verified database dump and a copy of `shared/storage/app/public`. The data stays on the droplet. Keep no copy elsewhere and delete transfer files afterwards.
3. Keep `MAIL_MAILER=log`, no `STRIPE_*` keys, and no scheduler.
4. Run the checks in step 6 against the sslip.io host. Time every step, so the freeze window below is a measured number, not a guess.
5. Rehearse the rollback in step 8 as well.

## 2. Pre-freeze checklist (T minus 1 day)

- [ ] All merges are done or deliberately held. CI is green on `main`. The newest DigitalOcean deploy of `main` succeeded and `current` is that commit.
- [ ] Rehearsal passed end to end and the measured freeze window is agreed **[Michael]**.
- [ ] Lower the TTL on every record that will move to 300 seconds, at least 24 hours before the switch.
- [ ] Complete DNS inventory exported from the current DNS host: A, AAAA, CNAME, MX, TXT (SPF, DKIM, DMARC, Postmark records, domain verification), SRV, CAA. Every record is recreated in DigitalOcean DNS **before** the nameserver switch. A missing MX or SPF record breaks mail.
- [ ] Postmark sending domain is verified and `MAIL_MAILER=postmark` is ready to set (see PR with the Postmark mailer).
- [ ] Stripe webhook endpoints and the GHL redirect URI are listed, with the new URL for each, so they can be switched in step 5. Webhook secrets are copied over through the vault, never chat or the repo.
- [ ] Fresh database backup on SiteGround from the Deploy workflow's backup action, verified with `gunzip -t`.
- [ ] Certificate plan below is ready, with a scoped DigitalOcean token stored as a server-side secret.
- [ ] Notice sent to affected tenants by Michael. Agents send no customer email.

### Certificate plan (wildcard, DNS-01 on DigitalOcean)

Tenant subdomains are `{slug}.reserveworx.com`, so one certificate covers `reserveworx.com`, `*.reserveworx.com`. A wildcard needs the DNS-01 challenge, and the domain's DNS must be hosted at DigitalOcean for the plugin to write the challenge record.

1. Install `python3-certbot-dns-digitalocean` (apt) on the droplet. certbot and the nginx plugin are already installed.
2. Create a DigitalOcean API token with the narrowest scope that can edit domain records. Store it in `/root/.secrets/certbot/digitalocean.ini` as `dns_digitalocean_token = ...`, mode 600, root only. It is never printed, committed or put in Actions.
3. Add the domain to DigitalOcean DNS (no nameserver change yet) and recreate all records there. This is a DNS change and needs **[Michael]**'s yes.
4. Dry run first, which hits the Let's Encrypt staging CA and issues nothing real:
   `certbot certonly --dns-digitalocean --dns-digitalocean-credentials /root/.secrets/certbot/digitalocean.ini -d reserveworx.com -d '*.reserveworx.com' --dry-run`
   The challenge only validates once DigitalOcean is the authoritative DNS host, so the dry run is also a check that step 3 is done. If the nameservers have not moved, the DNS-01 check will fail. Run the real issue right after the nameserver switch instead, and use the plain HTTP certificate on the sslip.io host until then.
5. Issue for real after the nameserver switch and point nginx at `/etc/letsencrypt/live/reserveworx.com/`. Add an HTTP to HTTPS redirect, `http2`, and HSTS only after verification passes.
6. Renewal: certbot's systemd timer renews. Test with `certbot renew --dry-run` and add a deploy hook `systemctl reload nginx`.
7. Custom tenant domains (not subdomains) are a separate problem. They need per-host certificates by the HTTP-01 challenge once each customer's DNS points at the droplet, or a managed certificate service. Decide before onboarding any, **[Michael]**.

## 3. Freeze (T0)

Order matters. Start the clock when step 3.1 begins.

1. Announce the freeze. Put the SiteGround app in maintenance mode with the Deploy workflow's `maintenance` action, so no new writes land. Bookings in flight finish or time out through their hold expiry.
2. Stop writers: pause the stall watch and any other automation, and confirm no deploy is running. Disable the SiteGround Laravel scheduler cron in Site Tools so no job runs mid-copy.
3. Confirm quiet: no new rows in `bookings`, `payments`, `refunds`, `holds` for 2 minutes.

## 4. Final database and storage sync

1. Take the final dump from SiteGround with the Deploy workflow's `backup` action. Verify `gunzip -t` and record the byte size and a SHA-256.
2. Move the dump to the droplet over SSH, straight from the workflow runner or via the approved export route. Do not stage it on a personal machine.
3. On the droplet, import into an empty `reserveworx` database. Create a pre-import snapshot or dump of the droplet database first. Then deploy `current` with `run_migrations` on, so any migrations newer than the dump run against the real data.
4. Sync storage: `rsync -a --delete` of `shared/storage/app/public` from SiteGround to `/var/www/reserveworx/shared/storage/app/public`, then fix ownership to `deploy:www-data`.
5. Compare row counts per main table (tenants, users, locations, services, resources, customers, bookings, payments, refunds, waivers) and the newest `created_at` per table, against the counts recorded just before the dump. They must match exactly.
6. Delete transfer files from every intermediate location.

## 5. Switch

1. Set the production values in `/var/www/reserveworx/shared/.env` on the droplet: `APP_URL=https://app.reserveworx.com`, `PLATFORM_DOMAINS`, `MAIL_MAILER=postmark`, Stripe live keys and webhook secret, GHL credentials. Then `php artisan config:cache`, reload php-fpm and restart workers.
2. Before the nameserver change, test through a `hosts` override or `curl --resolve app.reserveworx.com:443:129.212.180.114` with a real certificate. Run the checks in step 6.
3. Change the nameservers at the registrar to DigitalOcean's (`ns1/ns2/ns3.digitalocean.com`) **[Michael]**. Because TTL was lowered, most resolvers move within minutes, but some can take up to 48 hours. SiteGround must keep serving a read-only maintenance page for that period, so late resolvers never take a write.
4. Issue the wildcard certificate (certificate plan above), then enable the redirect.
5. Update the Stripe webhook endpoint URL, the GHL redirect URI and any other third-party callback to the new host.
6. Add the scheduler cron for `www-data`: `* * * * * php8.3 /var/www/reserveworx/current/artisan schedule:run`.
7. Lift maintenance on the droplet. Keep SiteGround read-only.

## 6. Verification

Run on the droplet host, then again on the public host after DNS has moved. Record the result of each line.

- [ ] `GET /up` returns 200 with `Application up`.
- [ ] `app.reserveworx.com` loads the sign-in page with a valid certificate and no mixed content, desktop and 390px.
- [ ] A tenant subdomain (use a staff-owned demo tenant, then Yak Shack) loads its booking widget and shows the right branding and services.
- [ ] Staff sign-in works and sessions persist across a page reload.
- [ ] Availability and a test booking on a demo tenant, with payments in test mode, creates a hold, a booking and a log-driver or Postmark message for a staff-owned address only.
- [ ] Stripe webhook test event arrives, is verified and is deduplicated. GHL connection test passes.
- [ ] Queue worker is running: `supervisorctl status` shows both RUNNING and a queued job completes.
- [ ] Scheduler heartbeat appears in the log within 2 minutes.
- [ ] Row counts match step 4. `storage/app/public` files open.
- [ ] No errors in `shared/storage/logs/` or `/var/log/nginx/error.log` after 30 minutes.
- [ ] Postmark message stream shows delivered mail for a staff test and no bounce.

If any line fails and is not fixed within 30 minutes, go to step 8.

## 7. After the switch

- Watch logs and the queue for 24 hours. Keep the SiteGround copy, its database and the final dump for at least 7 days, then ask **[Michael]** before deleting anything.
- Raise TTLs back to 3600 after 48 hours with no issue.
- Set up database backups on the droplet (nightly `mysqldump` to `/var/www/reserveworx/shared/backups`, keep 7, plus a DigitalOcean snapshot or off-site copy) and server monitoring. Enable DigitalOcean automated backups **[Michael]** (a billing change).
- Retire the SiteGround Deploy workflow only after the 7-day window and with Michael's yes.

## 8. Rollback

Before the nameserver switch (steps 1 to 4): lift SiteGround maintenance, re-enable its scheduler, and stop. The droplet data is discarded. Nothing public changed.

After the nameserver switch:

1. Put the droplet in maintenance mode so it stops taking writes.
2. Dump the droplet database and note any rows written since T0 (bookings, payments). They must be replayed on SiteGround by hand, or refunded and rebooked. Payments are not duplicated or lost. Check Stripe against the database.
3. Restore SiteGround: lift maintenance, re-enable the scheduler cron, and import the replayed rows if there are any.
4. Change the nameservers back to the previous values. TTL is still low, so this takes minutes for most resolvers.
5. Point Stripe webhooks, the GHL redirect URI and Postmark back to SiteGround.
6. Run the SiteGround checks from step 6.
7. Write up what failed before trying again.

Application rollback on the droplet alone (a bad release, data fine): dispatch `Deploy DigitalOcean` with action `rollback`, which re-points `current` at `previous` and never runs `migrate:rollback`.

## Open questions for Michael

- Freeze window length and date, after the rehearsal timing is known.
- Who holds the registrar login, and who changes nameservers.
- Custom tenant domains: approach and timing.
- Enable DigitalOcean automated backups (billing).
