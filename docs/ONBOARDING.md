# Adding a customer workspace (tenant)

A new customer gets `https://<slug>.reserveworx.com`, served by the same app as
app.reserveworx.com. Only step 1 is manual.

1. **SiteGround Site Tools → Domain → Subdomains → Create** `<slug>.reserveworx.com`
   under the reserveworx.com site. Keep the default document root
   (`www/<slug>.reserveworx.com/public_html`). Do not point it at the app folder
   and do not upload files there; the deploy writes the folder's `index.php`.
   Make sure SSL is issued for the subdomain (Security → SSL Manager) if it is
   not automatic.
2. **Add an entry to `config/tenants.php`:**
   ```php
   ['slug' => 'yakshack', 'name' => 'Yak Shack', 'owner_email' => 'owner@example.com', 'timezone' => 'America/New_York'],
   ```
   Optional `'hostname' => 'yakshack.reserveworx.com'` when the address differs from the slug
   (for example a workspace that already exists as `yak-shack`; the sync adopts it and only adds what is missing).
   Slug: 3–63 lowercase letters, digits and hyphens; reserved words are refused.
   Leave `owner_email` empty if not known yet; add it later in another PR.
3. **Open a PR, wait for green CI, squash-merge.** The normal Deploy runs
   `php artisan reserveworx:tenant:sync`, which idempotently creates:
   the workspace (onboarding status, default fee settings), one default
   location in the given timezone, the verified `<slug>.reserveworx.com`
   domain, and the owner's user and owner membership. It never sets prices,
   tax, fees or refund terms. The deploy then writes the front controller
   into `www/<slug>.reserveworx.com/public_html` if that folder exists. The
   deploy log shows `tenant=<slug> created=...`, `tenant_host=...` and either
   `tenant_front_controller=written` or `tenant_docroot=missing` (do step 1).
4. **What the owner receives:** the first time they are added, one email
   "reset your password" link to `https://<slug>.reserveworx.com/staff/reset-password/...`
   (valid 60 minutes). No password is generated or sent. If it expires they
   use "Forgot password?" on `https://<slug>.reserveworx.com/staff/login`.
   An owner who already has a ReserveWorx login keeps their password and can
   sign in at `/staff/login` directly.
5. The owner then sets up services, prices, tax and policies in the staff area.

Manual equivalent (same code path):
`php artisan reserveworx:tenant:create yakshack --name="Yak Shack" --owner-email=owner@example.com --timezone=America/New_York`
