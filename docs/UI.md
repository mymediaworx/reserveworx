# Web UI

Blade and Livewire 4, approved by the product owner on October 23, 2026 (open question 25). There is no Node build. The stylesheet is plain CSS in `resources/ui/app.css`, served by `GET /ui/app.css` with a content-hash query string. That route exists because SiteGround's `public_html` only receives `index.php` and a few fixed files at deploy. Livewire serves its own JavaScript from a PHP route.

## Where pages live

| Host | Path | What |
|---|---|---|
| Platform (`app.reserveworx.com`) | `/` | Product page |
| Tenant domain | `/staff/login` | Team sign in (session, host-only cookie) |
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

## Booking links

The customer widget at `/book/{token}` takes a widget token. Tokens are stored as SHA-256 hashes, so a new link is shown once, right after it is made. Making and turning off links uses the same rule as the widget-token API (`bookings.manage`). The widget's own markup and controller are unchanged. Only its stylesheet was restyled to the same palette.

## Demo data

`DemoShopSeeder` gives the two demo tenants a shop: Harbor Point Marina (kayaks, paddleboards, a guided sunset tour with departures, and promo code `WELCOME10`) and Mill Creek Landing (canoes). It runs only for demo tenants that have no locations yet.
