# Web UI

Blade and Livewire 4, approved by the product owner on October 23, 2026 (open question 25). There is no Node build. The stylesheet is plain CSS in `resources/ui/app.css`, served by `GET /ui/app.css` with a content-hash query string.

## Look

Mobile first, modeled on the BrandWorx mobile app (product owner, October 24, 2026). On phones there is a white app bar (logo or initials, workspace name, location, a bell for bookings waiting on payment, settings), a bottom tab bar (Home, Bookings, Calendar, Share, Menu) with a pill on the active tab, and a floating **+** for a new booking. From 1024px the tabs become a white side navigation. Home uses rounded stat cards with a grey time strip and round shortcut buttons. Type is Inter. Icons are Lucide line icons (ISC) in `resources/views/components/icon.blade.php`.

## Branding

`/staff/branding` edits Grok's `tenant_brandings` row (#88): business name, logo, main and highlight colors (with presets), and support contacts. It uses the same policy, `LogoStore`, and `branding.updated` audit action as `PATCH /api/v1/branding`. `App\Support\BrandPalette` turns the two colors into CSS variables for the staff app and the customer booking page, and guarantees readable contrast for any choice: 4.5:1 for text and button labels. It only ever prints validated hex values. The default look is BrandWorx green `#0F5C4D` with amber `#F59E0B`. Migration `2026_10_24_210000` moves workspaces that never chose colors to it.

## Share & embed

`/staff/share` replaces Booking links (`/staff/booking-links` redirects). Staff pick everything or one service, then a format:

- **Link:** the workspace address, or a service link. Copy, open, text, or email.
- **Button:** a plain HTML link styled in the workspace color. It needs no script.
- **On your page:** an `<iframe data-reserveworx>` plus `/embed.js`, which sizes the frame to the booking page.
- **Pop-up:** a button with `data-reserveworx-popup` plus `/embed.js`, which opens the booking page over the site (full screen on phones). Without the script the button still opens a new tab.

Links use `WidgetTokens::hostedPlain`, which is stable, so codes can be copied again any time. Named links that can be turned off are under **More links**. The booking page posts its height to the parent frame when embedded. The **Pay with card** form targets `_top`, because Stripe's payment page cannot run inside a frame. `/embed.js` sets no cookies. That route exists because SiteGround's `public_html` only receives `index.php` and a few fixed files at deploy. Livewire serves its own JavaScript from a PHP route.

An owner of the current workspace sees **All businesses** and **Add a business** in the side navigation and on the phone menu, including when they own only one. Other workspaces they belong to are listed there too. Switching posts to that workspace's verified address. The session cookie stays on the host that set it. A member who is not an owner of the current address does not see the rollup, and a workspace id they do not own is not found.

## Where pages live

| Host | Path | What |
|---|---|---|
| Platform (`app.reserveworx.com`) | `/` | Product page with platform sign in and a link to start a workspace |
| Platform | `/signup` | Public signup: business name, subdomain, owner name, email, and password. The address is checked as it is typed. Booking fees stay at the standard rates |
| Platform | `/platform/login`, `/platform` | Platform admins only: list and add workspaces |
| Platform | `/platform/forgot-password`, `/platform/reset-password/{token}` | Request a reset link and choose a new password. The link expires in 60 minutes. |
| Platform | `/platform/tenants/{id}` | Domains (add, mark verified), owners (add, with a one-time temporary password), booking fee rates, a private data export, status |
| Tenant domain | `/` | Public booking page for that workspace |
| Tenant domain | `/staff/login` | Team sign in (session, host-only cookie) |
| Tenant domain | `/staff/forgot-password`, `/staff/reset-password/{token}` | Request a reset link and choose a new password, for a team member of that workspace. The link expires in 60 minutes. |
| Tenant domain | `/staff` | Today: schedule, holds waiting on payment, booking fees. While the workspace is onboarding, the owner also sees the setup checklist and Go live |
| Tenant domain | `/staff/calendar` | Week or day view with location and service filters |
| Tenant domain | `/staff/schedule` | Day or week resource board. Rows are locations, then boats and pools grouped by category. Bookings and holds are blocks in that location's local time, with buffers shaded. Read only |
| Tenant domain | `/staff/feedback` | Ratings left after a visit, with the average and filters for stars, service, and a comment. Owner and manager can set the wait, the on/off switch, and the public review link |
| Tenant domain | `/feedback/{booking}` | Signed page where the customer rates that visit from 1 to 5 and can leave a comment. A 4 or 5 can show the workspace's review link. `/feedback/{booking}/opt-out` confirms before it stops later feedback email |
| Tenant domain | `/staff/bookings`, `/staff/bookings/{id}` | List, and one booking with price, payment, and all staff actions |
| Tenant domain | `/staff/bookings/new` | Pick a service, time or departure, and customer, then hold |
| Tenant domain | `/staff/setup/*` | Locations with hours and closures, boats and gear, services with lengths, gear, prices, and departures |
| Tenant domain | `/staff/settings` | Who pays the booking fee, hold length, Stripe connection |
| Tenant domain | `/staff/share` | Share & embed: link, button, on-page embed, pop-up, and named links |
| Tenant domain | `/staff/branding` | Name, logo, colors, support contacts |
| Tenant domain | `/staff/portfolio`, `/staff/portfolio.csv` | Rollup for every business this owner holds, or one of them. Presets are 7, 30, and 90 days, plus a custom range, with a trend and a CSV download |
| Tenant domain | `/staff/businesses/new` | Add a business. Fee rates stay at the standard defaults. An optional address is saved unverified |
| Tenant domain | `/staff/switch/{tenant}`, `/staff/enter/{token}` | Move to another business this login belongs to. The entry token lasts two minutes and works once, on that business's verified address |
| Tenant domain | `/staff/menu` | Phone menu: every tool as round buttons |
| Tenant domain | `/book/{token}` | Customer booking widget, styled to match. When `pay_at_pickup` is on, the review page also offers Pay at pickup. |

## Tenant isolation

- Page requests use `tenant.resolve`, `auth`, and `tenant.member`, like the API.
- Livewire's update endpoint is registered in `UiServiceProvider` behind `ResolveTenantUnlessPlatform`. On a tenant host, that middleware resolves the tenant from the domain for the whole update request. Livewire's persistent middleware cannot do this alone, because it runs middleware with an empty destination and `ResolveTenant` clears the context when its destination returns.
- `EnsureTenantMembership` and `EnsurePlatformAdmin` are persistent middleware, so they re-run on every component update.
- Components read the tenant from `TenantManager`, never from component state, and authorize each action with the existing policies.
- `StaffPagesTest` replays a component snapshot from one workspace on another workspace's domain and expects a refusal, with a control that the same call succeeds on the right domain.

## Behaviour mirrors the API

Each staff action calls the same service as the matching API endpoint (Allocator, BookingPricer, CheckoutService, Waitlist, WaitlistPromoter) and writes the same audit action. When an endpoint's orchestration changes, change the matching component too.

`App\Services\Booking\SlotFinder` lists bookable start times. It steps every 30 minutes within the location's opening hours (08:00–20:00 when none are set) and asks `Allocator::hint` for each slot. Its answers are hints. The hold transaction is the real check. When that hold is refused because the time is taken, the booking form lists the same nearby times and same-category assets the API returns in `offers`.

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
