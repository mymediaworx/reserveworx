# Web UI

Blade and Livewire 4, approved by the product owner on October 23, 2026 (open question 25). There is no Node build. The stylesheet is plain CSS in `resources/ui/app.css`, served by `GET /ui/app.css` with a content-hash query string. That route exists because SiteGround's `public_html` only receives `index.php` and a few fixed files at deploy. Livewire serves its own JavaScript from a PHP route.

## Where pages live

| Host | Path | What |
|---|---|---|
| Platform (`app.reserveworx.com`) | `/` | Product page with platform sign in |
| Platform | `/platform/login`, `/platform` | Platform admins only: list and add workspaces |
| Platform | `/platform/forgot-password`, `/platform/reset-password/{token}` | Request a reset link and choose a new password. The link expires in 60 minutes. |
| Platform | `/platform/tenants/{id}` | Domains (add, mark verified), owners (add, with a one-time temporary password), booking fee rates, a private data export, status |
| Tenant domain | `/` | Public booking page for that workspace |
| Tenant domain | `/staff/login` | Team sign in (session, host-only cookie) |
| Tenant domain | `/staff/forgot-password`, `/staff/reset-password/{token}` | Request a reset link and choose a new password, for a team member of that workspace. The link expires in 60 minutes. |
| Tenant domain | `/staff` | Today: schedule, holds waiting on payment, booking fees |
| Tenant domain | `/staff/calendar` | Week or day view with location and service filters |
| Tenant domain | `/staff/bookings`, `/staff/bookings/{id}` | List, and one booking with price, payment, and all staff actions |
| Tenant domain | `/staff/bookings/new` | Pick a service, time or departure, and customer, then hold |
| Tenant domain | `/staff/setup/*` | Locations with hours and closures, boats and gear, services with lengths, gear, prices, and departures |
| Tenant domain | `/staff/settings` | Who pays the booking fee, hold length, Stripe connection |
| Tenant domain | `/staff/booking-links` | Make, copy, embed, and turn off links to the customer booking widget |
| Tenant domain | `/book/{token}` | Customer booking widget, styled to match |

## Tenant isolation

- Page requests use `tenant.resolve`, `auth`, and `tenant.member`, like the API.
- Livewire's update endpoint is registered in `UiServiceProvider` behind `ResolveTenantUnlessPlatform`. On a tenant host, that middleware resolves the tenant from the domain for the whole update request. Livewire's persistent middleware cannot do this alone, because it runs middleware with an empty destination and `ResolveTenant` clears the context when its destination returns.
- `EnsureTenantMembership` and `EnsurePlatformAdmin` are persistent middleware, so they re-run on every component update.
- Components read the tenant from `TenantManager`, never from component state, and authorize each action with the existing policies.
- `StaffPagesTest` replays a component snapshot from one workspace on another workspace's domain and expects a refusal, with a control that the same call succeeds on the right domain.

## Behaviour mirrors the API

Each staff action calls the same service as the matching API endpoint (Allocator, BookingPricer, CheckoutService, Waitlist, WaitlistPromoter) and writes the same audit action. When an endpoint's orchestration changes, change the matching component too.

`App\Services\Booking\SlotFinder` lists bookable start times. It steps every 30 minutes within the location's opening hours (08:00–20:00 when none are set) and asks `Allocator::hint` for each slot. Its answers are hints. The hold transaction is the real check.

## Platform admin

Platform pages exist only on platform hosts (`platform.host`) and require `auth` plus `platform.admin`, which also re-runs on every Livewire update. Each action writes the same audit action as the platform API. Closing a workspace for good is refused until a private JSON export of that workspace has been saved. The download route is platform-admin only. A new owner's temporary password is shown once, and only its hash is stored.

## Booking links

The customer widget at `/book/{token}` takes a widget token. Tokens are stored as SHA-256 hashes, so a new link is shown once, right after it is made. Making and turning off links uses the same rule as the widget-token API (`bookings.manage`). The widget's own markup and controller are unchanged. Only its stylesheet was restyled to the same palette.

## Demo data

`DemoShopSeeder` gives the two demo tenants a shop: Harbor Point Marina (kayaks, paddleboards, a guided sunset tour with a departure each evening for the next 60 days, prices, opening hours, and promo code `WELCOME10`) and Mill Creek Landing (canoes). A deploy runs it for those two slugs only. A second run fills gaps and does not copy the shop onto any other workspace. The same deploy adds `michael@brandworx.ai` as an owner of Harbor Demo when that user already exists.

## Putting a demo workspace on a real address

Tenant pages are served only on a verified tenant domain, so a demo needs a real hostname, for example `harbor.reserveworx.com` for Harbor Demo.

1. **SiteGround, Site Tools → Domain → Subdomains:** create `harbor.reserveworx.com`. Then **Security → SSL Manager:** issue a Let's Encrypt certificate for it, and turn on **HTTPS Enforce** for that subdomain only.
2. **Front controller:** in the new subdomain's `public_html`, add an `index.php` that boots the live app. It is the same file the deploy writes for `app.reserveworx.com`, with an absolute root:

   ```php
   <?php
   use Illuminate\Foundation\Application;
   use Illuminate\Http\Request;
   define('LARAVEL_START', microtime(true));
   $root = '/home/customer/www/app.reserveworx.com/laravel/current';
   if (file_exists($maintenance = $root.'/storage/framework/maintenance.php')) { require $maintenance; }
   require $root.'/vendor/autoload.php';
   /** @var Application $app */
   $app = require_once $root.'/bootstrap/app.php';
   $app->handleRequest(Request::capture());
   ```

   Copy `.htaccess` from `app.reserveworx.com/public_html` too. A deploy does this itself when `$HOME/www/harbor.reserveworx.com/public_html` is already a real directory. It does not create that site, and it does not write under `www/reserveworx.com`. Because the file points at `laravel/current`, it follows every deploy and rollback.
3. **Domain row:** the same deploy runs `php artisan reserveworx:attach-verified-domain harbor-demo harbor.reserveworx.com`. That attaches the hostname to Harbor Demo and marks it verified. It does not replace the workspace's primary hostname. The deploy also grants `michael@brandworx.ai` as a platform administrator. When that account is created by the deploy, the password is stored only in `laravel/shared/platform-admin.txt` on the server.
4. **Try it:** open `https://harbor.reserveworx.com/` for the public booking page. Staff sign-in stays at `https://harbor.reserveworx.com/staff/login`. The Harbor Demo login is in `laravel/shared/demo-logins.txt`.

Wildcard subdomains would remove step 2 for every new operator. Whether SiteGround supports them is still open question 9.
