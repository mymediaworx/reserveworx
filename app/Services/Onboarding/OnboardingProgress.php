<?php

namespace App\Services\Onboarding;

use App\Models\User;

/**
 * One reading of an onboarding workspace. Every flag comes from stored rows.
 */
final class OnboardingProgress
{
    public function __construct(
        public bool $emailRequired,
        public bool $emailVerified,
        public ?string $acceptanceUserId,
        public bool $stripeConnected,
        public bool $serviceReady,
        public bool $hoursReady,
        public bool $brandingReady,
        public bool $testBookingPlaced,
        public bool $canGoLive,
    ) {}

    public function serviceAndHoursReady(): bool
    {
        return $this->serviceReady && $this->hoursReady;
    }

    public function canResend(User $user): bool
    {
        return $this->emailRequired
            && ! $this->emailVerified
            && $this->acceptanceUserId === $user->id;
    }
}
