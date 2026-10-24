<?php

namespace App\Services\Onboarding;

use App\Enums\ScheduleOwnerType;
use App\Enums\TenantStatus;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\Service;
use App\Models\SignupAcceptance;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\User;
use App\Tenancy\TenantScope;

/**
 * Reads the workspace. Nothing here is a stored checkbox.
 *
 * Stripe is done when the connected account can take card payments. That is
 * StripeAccount::canCharge(), the same gate CheckoutService uses before a charge.
 */
final class OnboardingChecklist
{
    public function assess(Tenant $tenant): OnboardingProgress
    {
        $acceptance = SignupAcceptance::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->first();
        $emailRequired = $acceptance instanceof SignupAcceptance;
        $emailVerified = true;
        $acceptanceUserId = null;

        if ($acceptance instanceof SignupAcceptance) {
            $acceptanceUserId = $acceptance->user_id;
            $owner = User::query()->find($acceptance->user_id);
            $emailVerified = $owner instanceof User && $owner->email_verified_at !== null;
        }

        $stripe = StripeAccount::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->first();
        $stripeConnected = $stripe instanceof StripeAccount && $stripe->canCharge();
        $serviceReady = Service::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->exists();
        $hoursReady = AvailabilitySchedule::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('owner_type', ScheduleOwnerType::Location)
            ->exists();
        $brandingReady = $this->brandingReady($tenant);
        $testBookingPlaced = Booking::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->exists();
        $canGoLive = $tenant->status === TenantStatus::Onboarding
            && $emailVerified
            && $stripeConnected
            && $serviceReady
            && $hoursReady
            && $brandingReady
            && $testBookingPlaced;

        return new OnboardingProgress(
            emailRequired: $emailRequired,
            emailVerified: $emailVerified,
            acceptanceUserId: $acceptanceUserId,
            stripeConnected: $stripeConnected,
            serviceReady: $serviceReady,
            hoursReady: $hoursReady,
            brandingReady: $brandingReady,
            testBookingPlaced: $testBookingPlaced,
            canGoLive: $canGoLive,
        );
    }

    private function brandingReady(Tenant $tenant): bool
    {
        $branding = TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $branding instanceof TenantBranding) {
            return false;
        }

        return $this->filled($branding->logo_path)
            || $this->filled($branding->logo_url)
            || $this->filled($branding->support_email)
            || $this->filled($branding->support_phone)
            || strtoupper($branding->primary_color) !== strtoupper(TenantBranding::DEFAULT_PRIMARY)
            || strtoupper($branding->accent_color) !== strtoupper(TenantBranding::DEFAULT_ACCENT)
            || trim($branding->display_name) !== trim($tenant->name);
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
