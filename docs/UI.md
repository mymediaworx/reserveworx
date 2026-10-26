# Web UI

Blade and Livewire 4, approved by the product owner on October 23, 2026 (open question 25). There is no Node build. The stylesheet is plain CSS in `resources/ui/app.css`, served by `GET /ui/app.css` with a content-hash query string.

## Look

Mobile first, modeled on the BrandWorx mobile app (product owner, October 24, 2026). On phones there is a white app bar (logo or initials, workspace name, location, a search icon, a bell for bookings waiting on payment, settings), a bottom tab bar (Home, Bookings, Calendar, Share, Menu) with a pill on the active tab, and a floating **+** for a new booking. From 1024px the tabs become a white side navigation. Home uses rounded stat cards with a grey time strip and round shortcut buttons. Type is Inter. Icons are Lucide line icons (ISC) in `resources/views/components/icon.blade.php`.

## Quick search

The search icon in the staff header, and Ctrl K or Cmd K, open search on every staff page. On a phone the panel fills the screen. From 720px it is a dialog. Search runs on the server. A word of at least two letters or numbers matches the start of a stored token, using `staff_search_token_idx` or, when the member is limited to locations, `staff_search_location_token_idx`. Each word keeps at most 300 matches, and each group shows 8 at a time.

Results are grouped into Bookings, Customers, and Inventory. Bookings match the reference (the booking id), or the customer name, email, or phone. Customers match name, email, or phone. Inventory matches a boat, guide, or pool name. Enter or a click opens the row: a booking opens that booking, a customer opens the bookings list for that email or name, and an item opens it on the inventory page. After a customer is anonymized, that booking still matches its reference and the result shows the reference. A member without `bookings.view` does not see bookings or customers. A member without `inventory.view` does not see inventory. Another workspace is never listed. A member limited to locations only sees bookings and inventory at those locations, and only customers who have a booking there.

G then B opens bookings. G then D opens the dashboard. N opens a new booking when that person can create one. ? lists the shortcuts. Those keys do nothing while the cursor is in a field, or while search or the shortcut list is open.

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
| Platform (`app.reserveworx.com`) | `/` | Product page with the eighteen industries, platform sign in, and a link to start a workspace |
| Platform | `/signup` | Public signup: business name, industry, subdomain, owner name, email, and password. The address is checked as it is typed. Booking fees stay at the standard rates. Industry defaults to boat rentals |
| Platform | `/platform/login`, `/platform` | Master admin, platform admins only: counts, filters, list, and add workspaces |
| Platform | `/platform/forgot-password`, `/platform/reset-password/{token}` | Request a reset link and choose a new password. The link expires in 60 minutes. |
| Platform | `/platform/tenants/{id}` | Industry, domains (add, mark verified), owners (add, with a one-time temporary password), booking fee rates, the GoHighLevel Location ID and Private Integration Token, a private data export, status |
| Tenant domain | `/` | Public booking page for that workspace. A rental service asks for a date, length, and party size (a GET form), then lists the open start times, with booked and closed times marked, before the name, email, and hold (a POST form). The review page names each person in that party |
| Tenant domain | `/staff/login` | Team sign in (session, host-only cookie) |
| Tenant domain | `/staff/forgot-password`, `/staff/reset-password/{token}` | Request a reset link and choose a new password, for a team member of that workspace. The link expires in 60 minutes. |
| Tenant domain | `/staff` | Today: schedule, overdue rentals, bookings that need attention, holds waiting on payment, booking fees. The overdue count links to `/staff/returns`, and the page lists each rental still out past its due time. While the workspace is onboarding, the owner also sees the setup checklist and Go live |
| Tenant domain | `/staff/calendar` | Week or day view with location and service filters |
| Tenant domain | `/staff/schedule` | Day or week resource board. Rows are locations, then boats and pools grouped by category. Bookings and holds are blocks in that location's local time, with buffers shaded. A closure is a hatched band on the rows it covers. Tapping a block opens a Move panel. A member with booking edit can choose a new start and another active resource in the same category. The length stays the same. A member who can create bookings can tap an empty hour (or, in week view, a day) on a boat or pool. The New booking panel is filled with that resource and start. They choose the service, variant, length, party, add-ons, and a customer, then hold, pay at pickup, or send a payment link. A closed time is refused |
| Tenant domain | `/staff/check-in` | Today's arrivals for one location, with search. The signed link checks that booking in and shows waivers and the balance due. A missing current waiver needs a reason |
| Tenant domain | `/staff/returns` | Rentals due back today, and anything still out past its time. Overdue rows show the minutes late. A return records the time, good / needs attention / damaged, and an optional note. A return after the end plus grace shows the late fee before it is confirmed. Staff apply that fee or waive it with a reason. A boat on that return can also get a damage report: severity, notes, photos, and an optional amount added to the customer total. `bookings.check_in` is required |
| Tenant domain | `/staff/incidents` | Damage and incident reports, filtered by open or resolved, severity, and location. Staff file one from returns or the booking page. An open report can be marked resolved. A serious report can put that boat on the Maintenance page for a chosen time. Future bookings that overlap are flagged and stay booked. `bookings.view` opens the list. `bookings.check_in` files and resolves. Photos are private |
| Tenant domain | `/staff/maintenance` | Maintenance windows and temporary blocks for one boat or pool, in that location's time zone, listed as in progress, upcoming, and past, with a filter for location and resource. Each current or upcoming window lists the future bookings it overlaps, marked Flagged, and leaves them booked. A serious incident can add a maintenance window here. Repeating inspections every N days or N rentals, with Mark done. `inventory.view` opens it and `inventory.manage` changes it |
| Tenant domain | `/staff/manifest`, `/staff/manifest.csv` | Dispatch sheet for a chosen date. Each location lists confirmed and no-show bookings in its own timezone, with the resource, customer, party, waivers, add-ons, balance, check-in, return time, and staff notes. Print puts one location on a page. The CSV is the same rows |
| Tenant domain | `/staff/reports`, `/staff/reports.csv` | Date range, default the last 30 days, and a location filter limited to the locations that member can use. Gross booking value by service and by date, kept apart from card payments, offline payments, refunds, taxes, and booking fees charged and refunded. Cancellation and no-show rates, new and repeat customers, and attribution. Utilization, conversion, average order value, and revenue per available resource-hour come from stored daily summaries. The CSV is the same figures. `bookings.view` is required |
| Tenant domain | `/staff/feedback` | Ratings left after a visit, with the average and filters for stars, service, and a comment. Owner and manager can set the wait, the on/off switch, and the public review link |
| Tenant domain | `/feedback/{booking}` | Signed page where the customer rates that visit from 1 to 5 and can leave a comment. A 4 or 5 can show the workspace's review link. `/feedback/{booking}/opt-out` confirms before it stops later feedback email |
| Tenant domain | `/staff/bookings`, `/staff/bookings/{id}` | List with status (including **Needs attention**: flagged held or confirmed bookings and payment exceptions), date, location, and item filters, a per-person saved view, and bulk check-in, return, cancel, and CSV. One booking with price, payment, and all staff actions. A damage report can be filed on an assigned boat, on an active boat from a pool allocated to the booking, or on the gear type when that pool has no individual boats |
| Tenant domain | `/staff/bookings/new` | Pick a service, time or departure, and customer, then hold |
| Tenant domain | `/staff/waitlist` | Waiting parties for one date and, optionally, one service. `bookings.view` opens it. `bookings.manage` can promote a party into a hold or cancel the row. Promote uses the same promoter as a freed slot |
| Tenant domain | `/waitlist/{entry}/leave` | Signed page from the waitlist email. Opening it does not leave. The button does |
| Tenant domain | `/staff/closures` | Full-day or time-range closures for one location, optionally one category or one boat or pool, with a reason. A closure can repeat every year. Confirmed bookings that overlap are listed and stay booked. `settings.view` opens the page. `settings.update` adds, edits, and removes |
| Tenant domain | `/staff/waivers` | The waivers in use, with version and signature counts, and earlier versions. `settings.view` opens it. `settings.update` publishes a waiver or a new version of one |
| Tenant domain | `/waivers/{booking}` | Signed page, linked from the confirmation page, the confirmation email, and check-in, where guests read the current waivers and sign: one per named person (a minor needs a guardian's name) or one for the booking. Shows when everything is signed |
| Tenant domain | `/staff/setup/*` | Locations with hours and closed dates, boats and gear, services with lengths, gear, prices, and departures. Location setup links to `/staff/closures`. A location's tax page can tax the customer booking fee. That switch stays off until an owner or manager turns it on |
| Tenant domain | `/staff/promo-codes` | List, add, edit, and turn off promo codes. A code is a percent or a fixed amount. Dates and a use limit are optional |
| Tenant domain | `/staff/import` | Owners and managers upload a CSV of customers or inventory, map columns, preview the first 20 rows, then import. The work runs in queued chunks. Templates are `/staff/import/templates/customers` and `/staff/import/templates/inventory` |
| Tenant domain | `/staff/settings` | Who pays the booking fee, hold length, Stripe connection, cancellation windows, and the no-show refund percent |
| Tenant domain | `/staff/team` | Invite by email, resend or revoke a pending invitation, change a role, remove a member, or limit that person to specific locations. Until a limit is saved, they can work at every location. Owners, and anyone else with `members.manage`, can open it. The signed accept link expires in 7 days. The location switcher under the staff header lists only the locations that member can use |
| Tenant domain | `/staff/invites/{membership}` | Signed page for that invitation. A new person sets a password. An existing account joins this workspace and keeps its password |
| Tenant domain | `/staff/share` | Share & embed: link, button, on-page embed, pop-up, and named links |
| Tenant domain | `/staff/branding` | Name, logo, colors, support contacts |
| Tenant domain | `/staff/portfolio`, `/staff/portfolio.csv` | Rollup for every business this owner holds, or one of them. Presets are 7, 30, and 90 days, plus a custom range, with a trend and a CSV download |
| Tenant domain | `/staff/businesses/new` | Add a business. Fee rates stay at the standard defaults. An optional address is saved unverified |
| Tenant domain | `/staff/switch/{tenant}`, `/staff/enter/{token}` | Move to another business this login belongs to. The entry token lasts two minutes and works once, on that business's verified address |
| Tenant domain | `/staff/menu` | Phone menu: every tool as round buttons |
| Tenant domain | `/book/{token}` | Customer booking widget, styled to match. After the open times or the tour departure, the form lists active equipment and add-ons. A flat or per-person row is a checkbox. A row sold each takes a quantity. The review page itemizes the chosen lines, takes an optional promo code, shows the discount, and names each person in the party. The booking fee is calculated on the subtotal after that discount, including those lines. When `pay_at_pickup` is on, the review page also offers Pay at pickup. The signed confirmation page lists the same lines, shows the named people, and shows a check-in code once the booking is confirmed |

## Tenant isolation

- Page requests use `tenant.resolve`, `auth`, and `tenant.member`, like the API.
- Livewire's update endpoint is registered in `UiServiceProvider` behind `ResolveTenantUnlessPlatform`. On a tenant host, that middleware resolves the tenant from the domain for the whole update request. Livewire's persistent middleware cannot do this alone, because it runs middleware with an empty destination and `ResolveTenant` clears the context when its destination returns.
- `EnsureTenantMembership` and `EnsurePlatformAdmin` are persistent middleware, so they re-run on every component update.
- Components read the tenant from `TenantManager`, never from component state, and authorize each action with the existing policies.
- `StaffPagesTest` replays a component snapshot from one workspace on another workspace's domain and expects a refusal, with a control that the same call succeeds on the right domain.

## Behaviour mirrors the API

Each staff action calls the same service as the matching API endpoint (Allocator, BookingPricer, CheckoutService, Waitlist, WaitlistPromoter) and writes the same audit action. When an endpoint's orchestration changes, change the matching component too.

When a hosted booking hold is refused because the time is taken, and that service has the waitlist on, the form offers Join waitlist with name, email, an optional phone, and party size. A closed time does not. The confirmation email uses the same mailer as other transactional mail and includes a signed link to `/waitlist/{entry}/leave`.

`App\Services\Booking\SlotFinder` lists bookable start times. It steps every 30 minutes within the location's opening hours (08:00–20:00 when none are set) and asks `Allocator::hint` for each slot. A slot inside a closure is marked closed and is not offered, including when the waitlist is on. Its answers are hints. The hold transaction is the real check. When that hold is refused because the time is taken, the booking form lists the same nearby times and same-category assets the API returns in `offers`. A closed time says "That time is closed." and does not list those hints.

`/staff/schedule` uses `Allocator::move` for a new start and a same-category asset. That is the reschedule and reassignment lock, with this booking left out of the recheck. A conflict lists those same nearby-time hints. The audit actions and the `booking.guest_rescheduled` hook are the ones the booking screen and the guest reschedule already write. A price change stays on increase checkout and the proportional decrease refund.

A new booking from an empty slot uses `Allocator::hold` with the same locks. The services and variants offered are the ones that consume that boat or pool. The quote is `BookingPricer::quote` on the direct channel, with tax 0, no promo, and a full deposit, which is the public checkout quote. Pay at pickup is the existing offline payment. The payment link is the existing Stripe checkout. A taken slot stays empty and shows the usual conflict message. The audit action is `booking.held` with channel `staff_schedule`. `/staff/bookings/new` is unchanged.

## Platform admin

Platform pages exist only on platform hosts (`platform.host`) and require `auth` plus `platform.admin`, which also re-runs on every Livewire update. `/platform` is the master admin for every workspace: status counts, industry counts, search, and status and industry filters. Each action writes the same audit action as the platform API. Changing industry writes `tenant.industry_updated` and does not write a row when the industry is already that value. Closing a workspace for good is refused until a private JSON export of that workspace has been saved. The download route is platform-admin only. A new owner's temporary password is shown once, and only its hash is stored. Staff pages use the workspace industry for the resource name and the sentences that used to say boat. Boat rentals and marinas keep those sentences. A platform administrator still cannot open a workspace's staff pages without a membership. The GoHighLevel token is typed on that workspace page and is not shown again. The latest booking messages list the kind, status, and try count. Tenant staff cannot open the page.

## Booking links

The customer widget at `/book/{token}` takes a widget token. Tokens are stored as SHA-256 hashes, so a new link is shown once, right after it is made. Making and turning off links uses the same rule as the widget-token API (`bookings.manage`). The review page takes an optional promo code, shows the discount, and recalculates the booking fee on the subtotal after that discount. Equipment and add-ons chosen on the booking form are part of that subtotal and are listed on the review page and the confirmation. It also names each person in the party, including a child and that child's parent or guardian, up to the party size. The confirmation page keeps that list. The widget does not use a cookie session. Its stylesheet matches the staff palette.

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
