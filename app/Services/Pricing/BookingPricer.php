<?php

namespace App\Services\Pricing;

use App\Domain\Fees\Disclosures\DisclosurePublisher;
use App\Domain\Fees\FeeCalculator;
use App\Domain\Fees\FeeChannel;
use App\Domain\Fees\FeeRate;
use App\Domain\Pricing\PriceRuleInput;
use App\Domain\Pricing\PricingEngine;
use App\Enums\FeePayer;
use App\Models\Booking;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\PromoCode;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\Catalog\BookingAddOnLines;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class BookingPricer
{
    public function __construct(
        private readonly PricingEngine $pricing,
        private readonly DisclosurePublisher $disclosures,
        private readonly BookingAddOnLines $addOnLines,
    ) {}

    public function quote(Booking $booking, Tenant $tenant, FeeChannel $channel, int $taxBasisPoints, ?string $promoCode, int $depositPercent): PricingSnapshot
    {
        $existing = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if ($existing instanceof PricingSnapshot) {
            return $existing;
        }

        $setting = TenantSetting::query()->where('tenant_id', $tenant->id)->first();
        $rate = $setting instanceof TenantSetting
            ? FeeRate::fromTenantSetting($setting, $channel)
            : FeeRate::defaultFor($channel);
        $payer = $setting instanceof TenantSetting ? $setting->fee_payer : FeePayer::CustomerPaid;
        $rules = PriceRule::query()->where('service_id', $booking->service_id)->get()->map(function (PriceRule $rule): PriceRuleInput {
            return new PriceRuleInput(
                $rule->kind,
                $rule->amount_cents,
                $rule->window,
                $rule->duration_minutes,
                $rule->starts_on?->toDateString(),
                $rule->ends_on?->toDateString(),
            );
        })->all();

        $promo = null;
        $percent = null;
        $amount = null;

        if ($promoCode !== null && $promoCode !== '') {
            $promo = PromoCode::query()->where('code', strtoupper($promoCode))->where('active', true)->first();

            if (! $promo instanceof PromoCode) {
                throw new InvalidArgumentException('That promo code is not active.');
            }

            $percent = $promo->percent_off;
            $amount = $promo->amount_off_cents;
        }

        $location = $booking->location;
        $zone = $location !== null ? $location->timezone : 'UTC';
        $on = CarbonImmutable::instance($booking->starts_at)->timezone($zone);
        $seconds = CarbonImmutable::instance($booking->ends_at)->utc()->getTimestamp() - CarbonImmutable::instance($booking->starts_at)->utc()->getTimestamp();
        $duration = intdiv($seconds, 60);

        if ($duration < 1) {
            throw new InvalidArgumentException('Choose a valid interval.');
        }

        $addOnLines = $this->addOnLines->snapshot($booking);
        $addOnsCents = 0;

        foreach ($addOnLines as $line) {
            $addOnsCents += $line['total_cents'];
        }

        $priced = $this->pricing->price(
            $channel,
            $rate,
            $payer,
            $rules,
            $booking->participants,
            $duration,
            $on,
            $taxBasisPoints,
            $percent,
            $amount,
            $promo instanceof PromoCode ? $promo->code : null,
            $depositPercent,
            $addOnsCents,
            $addOnLines,
        );
        $disclosure = $this->disclosures->currentBookingFeeRefundTerms($tenant, $payer);
        $payload = $priced->toSnapshot();
        $payload['tax_basis_points'] = $taxBasisPoints;
        $payload['priced_participants'] = $booking->participants;
        $payload['priced_duration_minutes'] = $duration;
        $payload['priced_on'] = $on->toDateString();
        $payload['promo_percent_off'] = $percent;
        $payload['promo_amount_off_cents'] = $amount;

        return PricingSnapshot::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'currency' => $setting instanceof TenantSetting ? $setting->currency : 'USD',
            'payload' => $payload,
            'rules_version' => FeeCalculator::RULES_VERSION,
            'disclosure_version_id' => $disclosure?->id,
        ]);
    }
}
