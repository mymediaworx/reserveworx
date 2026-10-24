<?php

namespace App\Services\Pricing;

use App\Domain\Fees\Disclosures\DisclosurePublisher;
use App\Domain\Fees\FeeCalculator;
use App\Domain\Fees\FeeChannel;
use App\Domain\Fees\FeeRate;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Pricing\VariantPrice;
use App\Enums\FeePayer;
use App\Models\Booking;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\PromoCode;
use App\Models\ServiceVariant;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\Catalog\BookingAddOnLines;
use App\Services\Catalog\BookingOperatorFeeLines;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class BookingPricer
{
    public function __construct(
        private readonly PricingEngine $pricing,
        private readonly DisclosurePublisher $disclosures,
        private readonly BookingAddOnLines $addOnLines,
        private readonly BookingOperatorFeeLines $operatorFees,
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
        $variant = $this->variant($booking);
        $rules = VariantPrice::overrideBase(PriceRule::inputsFor($booking->service_id), $variant?->price_override_cents);

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

        $operatorDefinitions = $this->operatorFees->definitionsFor($booking->service_id, $booking->location_id);
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
            $operatorDefinitions,
        );
        $disclosure = $this->disclosures->currentBookingFeeRefundTerms($tenant, $payer);
        $payload = $priced->toSnapshot();
        $payload['operator_fees'] = $this->operatorFees->replace($booking, $priced->operatorFeeLines);
        $payload['tax_basis_points'] = $taxBasisPoints;
        $payload['priced_participants'] = $booking->participants;
        $payload['priced_duration_minutes'] = $duration;
        $payload['priced_on'] = $on->toDateString();
        $payload['priced_local_start'] = $on->format('H:i');
        $payload['promo_percent_off'] = $percent;
        $payload['promo_amount_off_cents'] = $amount;

        if ($variant instanceof ServiceVariant) {
            $payload['variant_id'] = $variant->id;
            $payload['variant_name'] = $variant->name;
            $payload['price_override_cents'] = $variant->price_override_cents;
        }

        return PricingSnapshot::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'currency' => $setting instanceof TenantSetting ? $setting->currency : 'USD',
            'payload' => $payload,
            'rules_version' => FeeCalculator::RULES_VERSION,
            'disclosure_version_id' => $disclosure?->id,
        ]);
    }

    private function variant(Booking $booking): ?ServiceVariant
    {
        $id = $booking->service_variant_id;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $variant = ServiceVariant::query()->where('service_id', $booking->service_id)->find($id);

        return $variant instanceof ServiceVariant ? $variant : null;
    }
}
