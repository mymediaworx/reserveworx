<?php

namespace App\Support;

use App\Enums\Industry;
use App\Models\User;
use App\Tenancy\Access;

/**
 * Static how-to pages for staff and platform administrators.
 * The copy does not read bookings, prices, payments, taxes, or fees.
 */
final class UserHandbook
{
    public function __construct(private readonly Access $access) {}

    /**
     * @return list<array{heading: string, topics: list<array{title: string, body: string, href: string}>}>
     */
    public function forMember(Industry $industry, User $user, string $tenantId, bool $ownerOrManager, bool $restricted, bool $owner): array
    {
        $sections = [];

        foreach ($this->staff($industry) as $section) {
            $topics = [];

            foreach ($section['topics'] as $topic) {
                if (! $this->allowed($topic, $user, $tenantId, $ownerOrManager, $restricted, $owner)) {
                    continue;
                }

                $topics[] = [
                    'title' => $topic['title'],
                    'body' => $topic['body'],
                    'href' => $topic['href'],
                ];
            }

            if ($topics === []) {
                continue;
            }

            $sections[] = [
                'heading' => $section['heading'],
                'topics' => $topics,
            ];
        }

        return $sections;
    }

    /**
     * @return list<array{heading: string, topics: list<array{title: string, body: string, href: string}>}>
     */
    public function platform(): array
    {
        return [
            [
                'heading' => 'Platform',
                'topics' => [
                    [
                        'title' => 'Workspaces',
                        'body' => 'Every business, with its status and industry. Open one from the list to set domains and owners.',
                        'href' => '/platform',
                    ],
                    [
                        'title' => 'Administrators',
                        'body' => 'Remove someone else’s platform access. Their account, password, and workspace memberships stay. The last administrator cannot be removed, and you cannot remove yourself while another administrator remains. Opening the page does not write a row.',
                        'href' => '/platform/administrators',
                    ],
                    [
                        'title' => 'Readiness',
                        'body' => 'Whether this server passes the local checks. The same lines are printed by reserveworx:readiness. It does not call Stripe.',
                        'href' => '/platform/readiness',
                    ],
                    [
                        'title' => 'Performance',
                        'body' => 'How many database queries the product page, signup, and one booking page use on this server, and whether memory is at least 128M. The same lines are printed by reserveworx:performance. It does not call the public internet and does not keep a row.',
                        'href' => '/platform/performance',
                    ],
                    [
                        'title' => 'Limits',
                        'body' => 'Whether scheduled work stays inside the shared-host window. The same lines are printed by reserveworx:limits. It does not print secrets and does not keep a row.',
                        'href' => '/platform/limits',
                    ],
                    [
                        'title' => 'Security',
                        'body' => 'Whether stored credentials are encrypted, card columns are absent, the session cookie is HTTP-only and stays on the current host, form posts use the CSRF check, and owner, manager, and staff have to match the role catalog. Sign-in pages cannot be framed. The booking page can. The same lines are printed by reserveworx:security. A sample token is thrown away. It does not call Stripe and does not keep a row.',
                        'href' => '/platform/security',
                    ],
                    [
                        'title' => 'Isolation',
                        'body' => 'Whether another workspace can read a customer, and whether a request with no workspace can. The same lines are printed by reserveworx:isolation. A sample customer is thrown away. It does not call Stripe and does not keep a row.',
                        'href' => '/platform/isolation',
                    ],
                    [
                        'title' => 'Upgrade',
                        'body' => 'How a later release replaces the live one, and how a private database dump is restored. The page does not run a deploy or a restore. Rollback swaps the previous release and does not undo migrations.',
                        'href' => '/platform/upgrade',
                    ],
                    [
                        'title' => 'Demo',
                        'body' => 'Harbor Demo and Inland Demo, each with its own locations, services, and bookings. The same lines are printed by reserveworx:demo. A missing demo workspace is not created.',
                        'href' => '/platform/demo',
                    ],
                    [
                        'title' => 'A workspace',
                        'body' => 'From the list, open a workspace for its industry, domains, and owners. The GoHighLevel Location ID is saved there. The private token is typed once and is not shown again. Export the workspace before closing it for good. Booking fee rates are on that page. This handbook does not change them.',
                        'href' => '/platform',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array{heading: string, topics: list<array{title: string, body: string, href: string, permission: ?string, owners: bool, wholeWorkspace: bool, ownerOnly: bool}>}>
     */
    private function staff(Industry $industry): array
    {
        $unit = $industry->unit();
        $catalog = $industry->catalog();

        return [
            [
                'heading' => 'Daily work',
                'topics' => [
                    $this->topic('Home', 'Today\'s bookings, what is checked out, and anything overdue.', '/staff', 'bookings.view'),
                    $this->topic('New booking', 'Hold a time for a customer. Pay at pickup and the card link stay on that booking.', '/staff/bookings/new', 'bookings.manage'),
                    $this->topic('Bookings', 'Filter the list and open one booking to check it in, return it, or cancel it.', '/staff/bookings', 'bookings.view'),
                    $this->topic('Calendar', 'Day, week, month, and agenda. A visit stays on the date in its location’s time zone. The page refreshes about once a minute while the tab is open.', '/staff/calendar', 'bookings.view'),
                    $this->topic('Schedule', 'Each '.$unit.' for the day. Tap an empty hour to start a booking, or tap a booking to move it.', '/staff/schedule', 'bookings.view'),
                    $this->topic('Check in', 'Today\'s arrivals for one location.', '/staff/check-in', 'bookings.check_in'),
                    $this->topic('Returns', 'Rentals due back today, and anything still out.', '/staff/returns', 'bookings.check_in'),
                    $this->topic('Incidents', 'Damage reports. A serious one can schedule maintenance. The booking stays booked.', '/staff/incidents', 'bookings.view'),
                    $this->topic('Maintenance', 'Take a '.$unit.' or a pool out of service, end that window early, or mark an inspection done. Times use that location’s time zone.', '/staff/maintenance', 'inventory.view'),
                    $this->topic('Manifest', 'The day\'s dispatch sheet. Print it, or download the same rows.', '/staff/manifest', 'bookings.view'),
                    $this->topic('Load sheet', 'How many of each shared fleet item go to each location that day.', '/staff/load-sheet', 'bookings.view'),
                    $this->topic('Reports', 'Figures for a date range you choose, and the same download.', '/staff/reports', 'bookings.view'),
                    $this->topic('Waitlist', 'Parties waiting on a full time.', '/staff/waitlist', 'bookings.view'),
                    $this->topic('Feedback', 'Ratings guests leave after a visit.', '/staff/feedback', 'bookings.view'),
                    $this->topic('Share', 'The booking link, a button, and an embed for the website.', '/staff/share', 'bookings.view'),
                ],
            ],
            [
                'heading' => 'Set up the workspace',
                'topics' => [
                    $this->topic('Setup guide', 'The checklist for a new workspace. It links to the real pages.', '/staff/setup', null, true),
                    $this->topic('All businesses', 'Totals for the businesses this owner holds, or for one of them. The same rows download. A business this owner does not hold is left out.', '/staff/portfolio', null, false, false, true),
                    $this->topic('Add a business', 'Start another workspace. It begins in onboarding. An optional address is saved unverified.', '/staff/businesses/new', null, false, false, true),
                    $this->topic('Locations', 'Opening hours and closed dates.', '/staff/setup/locations', 'inventory.view'),
                    $this->topic('Closures', 'A closed day or a closed part of the day. Bookings that overlap stay booked.', '/staff/closures', 'settings.view'),
                    $this->topic('Waivers', 'The current waiver text. A signed copy downloads from the booking.', '/staff/waivers', 'settings.view'),
                    $this->topic($catalog, 'Asset types, prep time, return time, and what is available to rent.', '/staff/setup/resources', 'inventory.view'),
                    $this->topic('Services', 'Services, lengths, options, and departures. An empty departure can be cancelled. One that still has a booking stays scheduled. Linked gear can be removed. A booking that already took it stays booked. Prices stay on the service page.', '/staff/setup/services', 'inventory.view'),
                    $this->topic('Promo codes', 'Codes guests can enter on the review page.', '/staff/promo-codes', 'inventory.view'),
                    $this->topic('Add-ons', 'Optional equipment and extras on the booking page.', '/staff/add-ons', 'inventory.view'),
                    $this->topic('Corporate accounts', 'Named accounts for a staff booking or a quote.', '/staff/corporate-accounts', 'inventory.view'),
                    $this->topic('Branding', 'The name, logo, and colors guests and staff see.', '/staff/branding', 'settings.view'),
                    $this->topic('Team', 'Invite people, set a role, or limit someone to locations.', '/staff/team', 'members.manage'),
                    $this->topic('Import', 'Upload a CSV of customers or inventory.', '/staff/import', null, true),
                    $this->topic('Settings', 'Hold length, guest cancellation, shared-gear lock blocks, card payments, cancellation windows, and receipt email are saved here. This handbook does not change them.', '/staff/settings', 'settings.view'),
                    $this->topic('Customer records', 'Download one person\'s record, then remove their name, email, and phone. Bookings and payments stay.', '/staff/customers', 'customers.privacy', false, true),
                    $this->topic('Audit log', 'The latest changes in this workspace.', '/staff/audit', 'audit.view', false, true),
                    $this->topic('Integrations', 'Tries sent out for this workspace. A stopped try can be sent again. The token is not shown.', '/admin/integrations', 'settings.view'),
                ],
            ],
        ];
    }

    /**
     * @return array{title: string, body: string, href: string, permission: ?string, owners: bool, wholeWorkspace: bool, ownerOnly: bool}
     */
    private function topic(
        string $title,
        string $body,
        string $href,
        ?string $permission = null,
        bool $owners = false,
        bool $wholeWorkspace = false,
        bool $ownerOnly = false,
    ): array {
        return [
            'title' => $title,
            'body' => $body,
            'href' => $href,
            'permission' => $permission,
            'owners' => $owners,
            'wholeWorkspace' => $wholeWorkspace,
            'ownerOnly' => $ownerOnly,
        ];
    }

    /**
     * @param  array{title: string, body: string, href: string, permission: ?string, owners: bool, wholeWorkspace: bool, ownerOnly: bool}  $topic
     */
    private function allowed(array $topic, User $user, string $tenantId, bool $ownerOrManager, bool $restricted, bool $owner): bool
    {
        if ($topic['ownerOnly'] && ! $owner) {
            return false;
        }

        if ($topic['owners'] && ! $ownerOrManager) {
            return false;
        }

        if ($topic['wholeWorkspace'] && $restricted) {
            return false;
        }

        if ($topic['permission'] !== null && ! $this->access->allows($user, $tenantId, $topic['permission'])) {
            return false;
        }

        return true;
    }
}
