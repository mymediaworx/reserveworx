<?php

namespace App\Services\Onboarding;

use App\Enums\ScheduleOwnerType;
use App\Models\AvailabilitySchedule;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Service;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\TenantSetupGuide;
use App\Models\WaiverVersion;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The owner's step-by-step setup guide. Each step reads real workspace rows
 * where it can; otherwise the owner marks it done or skips it. This class only
 * reads settings and stores the guide's own done/skipped/hidden flags. It never
 * writes prices, tax, fees or refund values.
 */
final class SetupGuide
{
    /** Workspaces created from this moment on show the guide by default. */
    public const DEFAULT_ON_SINCE = '2026-10-26 00:00:00';

    public function __construct(private OnboardingChecklist $checklist) {}

    /**
     * @return list<array{key: string, icon: string, title: string, summary: string, href: ?string, action: string, auto: bool, detected: bool, state: string}>
     */
    public function steps(Tenant $tenant): array
    {
        $id = $tenant->id;
        $count = fn (string $table): bool => DB::table($table)->where('tenant_id', $id)->exists();
        $firstLocation = Location::withoutGlobalScope(TenantScope::class)->where('tenant_id', $id)->orderBy('created_at')->value('id');
        $services = Service::withoutGlobalScope(TenantScope::class)->where('tenant_id', $id);
        $stripe = StripeAccount::withoutGlobalScope(TenantScope::class)->where('tenant_id', $id)->first();
        $team = Membership::withoutGlobalScope(TenantScope::class)->where('tenant_id', $id)->count();

        $definitions = [
            ['profile', 'palette', 'Business profile and branding', 'Add your logo, colors and a support email or phone.', '/staff/branding', 'Open branding', true,
                $this->checklist->assess($tenant)->brandingReady],
            ['locations', 'pin', 'Locations and hours', 'Add where you operate and when you are open.', '/staff/setup/locations', 'Open locations',  true,
                is_string($firstLocation) && AvailabilitySchedule::withoutGlobalScope(TenantScope::class)->where('tenant_id', $id)->where('owner_type', ScheduleOwnerType::Location)->exists()],
            ['services', 'boat', 'Services: rentals and tours', 'Create what customers can book, like a kayak rental or a sunset tour.', '/staff/setup/services', 'Open services', true,
                (clone $services)->exists()],
            ['equipment', 'anchor', 'Equipment', 'List the boats, boards, or gear each service uses. A shared fleet has one count for the whole workspace and a daily load sheet.', '/staff/setup/resources', 'Open equipment', true,
                $count('assets') || $count('pools') || $count('fleet_items')],
            ['pricing', 'dollar', 'Pricing', 'Set the price for each service and duration.', '/staff/setup/services', 'Open services', true,
                $count('price_rules')],
            ['tax', 'trending', 'Tax and booking fee', 'Set tax for each location and choose who pays the booking fee.', is_string($firstLocation) ? '/staff/locations/'.$firstLocation.'/tax' : '/staff/setup/locations', 'Open tax settings', true,
                $count('location_tax_rules')],
            ['cancellation', 'return', 'Cancellation policy', 'Decide what customers get back when they cancel or do not show.', '/staff/settings', 'Open settings', true,
                $count('cancellation_policies')],
            ['waivers', 'shield', 'Waivers', 'Write the waiver guests sign before they pay.', '/staff/waivers', 'Open waivers', true,
                WaiverVersion::withoutGlobalScope(TenantScope::class)->where('tenant_id', $id)->exists()],
            ['addons', 'plus', 'Add-ons', 'Extras like dry bags or paddles guests can add when they book.', '/staff/add-ons', 'Open add-ons', true,
                $count('add_ons')],
            ['deposit', 'wallet', 'Default deposit', 'A deposit for each service. There is no staff screen for this yet.', null, 'Ask support', false, false],
            ['staff', 'users', 'Staff', 'Invite your managers and guides.', '/staff/team', 'Open team', true,
                $team > 1],
            ['payments', 'wallet', 'Payments with Stripe', 'Connect Stripe so customers can pay by card.', '/staff/settings', 'Open payments', true,
                $stripe instanceof StripeAccount && $stripe->canCharge()],
            ['share', 'share', 'Share your booking link', 'Put the booking link on your website, social profiles and texts.', '/staff/share', 'Open share & embed', false, false],
        ];

        $saved = $this->guide($tenant);
        $states = $saved instanceof TenantSetupGuide && is_array($saved->step_states) ? $saved->step_states : [];
        $steps = [];

        foreach ($definitions as [$key, $icon, $title, $summary, $href, $action, $auto, $detected]) {
            $manual = $states[$key] ?? null;
            $state = $detected ? 'done' : (in_array($manual, [TenantSetupGuide::DONE, TenantSetupGuide::SKIPPED], true) ? $manual : 'todo');
            $steps[] = compact('key', 'icon', 'title', 'summary', 'href', 'action', 'auto', 'detected', 'state');
        }

        return $steps;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return ['profile', 'locations', 'services', 'equipment', 'pricing', 'tax', 'cancellation', 'waivers', 'addons', 'deposit', 'staff', 'payments', 'share'];
    }

    /**
     * @param  list<array{state: string}>  $steps
     */
    public function finished(array $steps): bool
    {
        return collect($steps)->every(fn (array $step): bool => $step['state'] !== 'todo');
    }

    public function isHidden(Tenant $tenant): bool
    {
        $guide = $this->guide($tenant);

        if ($guide instanceof TenantSetupGuide) {
            return $guide->hidden;
        }

        return CarbonImmutable::parse($tenant->created_at)->lt(CarbonImmutable::parse(self::DEFAULT_ON_SINCE, 'UTC'));
    }

    /** Shown on the staff home: not hidden and not finished. */
    public function showsOnHome(Tenant $tenant): bool
    {
        return ! $this->isHidden($tenant) && ! $this->finished($this->steps($tenant));
    }

    public function setHidden(Tenant $tenant, bool $hidden): void
    {
        $guide = $this->guideOrNew($tenant);
        $guide->hidden = $hidden;
        $guide->save();
    }

    public function mark(Tenant $tenant, string $key, ?string $state): void
    {
        abort_unless(in_array($key, $this->keys(), true), 404);
        abort_unless($state === null || in_array($state, [TenantSetupGuide::DONE, TenantSetupGuide::SKIPPED], true), 422);
        $guide = $this->guideOrNew($tenant);
        $states = $guide->step_states ?? [];

        if ($state === null) {
            unset($states[$key]);
        } else {
            $states[$key] = $state;
        }

        $guide->step_states = $states;
        $guide->save();
    }

    private function guide(Tenant $tenant): ?TenantSetupGuide
    {
        return TenantSetupGuide::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->first();
    }

    private function guideOrNew(Tenant $tenant): TenantSetupGuide
    {
        $guide = $this->guide($tenant);

        if ($guide instanceof TenantSetupGuide) {
            return $guide;
        }

        $guide = new TenantSetupGuide;
        $guide->tenant_id = $tenant->id;
        $guide->hidden = $this->isHidden($tenant);

        return $guide;
    }
}
