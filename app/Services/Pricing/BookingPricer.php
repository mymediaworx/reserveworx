<?php

namespace App\Services\Pricing;

use App\Domain\Fees\Disclosures\DisclosurePublisher;
use App\Domain\Fees\FeeCalculator;
use App\Domain\Fees\FeeChannel;
use App\Domain\Fees\FeeRate;
use App\Domain\Pricing\PricedBooking;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Pricing\VariantPrice;
use App\Enums\FeePayer;
use App\Models\Booking;
use App\Models\DisclosureVersion;
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

        $location = $booking->location;
        $selection = $this->priceSelection(
            $tenant,
            $booking->service_id,
            $booking->location_id,
            $location !== null ? $location->timezone : 'UTC',
            $this->variant($booking),
            CarbonImmutable::instance($booking->starts_at),
            CarbonImmutable::instance($booking->ends_at),
            $booking->participants,
            $this->addOnLines->snapshot($booking),
            $channel,
            $taxBasisPoints,
            $promoCode,
            $depositPercent,
        );
        $payload = $selection['payload'];
        $payload['operator_fees'] = $this->operatorFees->replace($booking, $selection['priced']->operatorFeeLines);

        return PricingSnapshot::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'currency' => $selection['currency'],
            'payload' => $payload,
            'rules_version' => FeeCalculator::RULES_VERSION,
            'disclosure_version_id' => $selection['disclosure']?->id,
        ]);
    }

    /**
     * The same direct quote public checkout stores, without writing a snapshot
     * or copying operator-fee rows. Fee rates are read, not changed.
     *
     * @param  list<array<string, mixed>>  $addOnLines
     * @return array{priced: PricedBooking, currency: string, disclosure: ?DisclosureVersion, payload: array<string, mixed>}
     */
    public function priceSelection(
        Tenant $tenant,
        string $serviceId,
        ?string $locationId,
        string $timezone,
        ?ServiceVariant $variant,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        int $participants,
        array $addOnLines,
        FeeChannel $channel,
        int $taxBasisPoints,
        ?string $promoCode,
        int $depositPercent,
    ): array {
        if ($variant instanceof ServiceVariant && $variant->service_id !== $serviceId) {
            throw new InvalidArgumentException('That variant is not available.');
        }

        $setting = TenantSetting::query()->where('tenant_id', $tenant->id)->first();
        $rate = $setting instanceof TenantSetting
            ? FeeRate::fromTenantSetting($setting, $channel)
            : FeeRate::defaultFor($channel);
        $payer = $setting instanceof TenantSetting ? $setting->fee_payer : FeePayer::CustomerPaid;
        $rules = VariantPrice::overrideBase(PriceRule::inputsFor($serviceId), $variant?->price_override_cents);

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

        $zone = $timezone !== '' ? $timezone : 'UTC';
        $on = $startsAt->timezone($zone);
        $seconds = $endsAt->utc()->getTimestamp() - $startsAt->utc()->getTimestamp();
        $duration = intdiv($seconds, 60);

        if ($duration < 1) {
            throw new InvalidArgumentException('Choose a valid interval.');
        }

        $addOnsCents = 0;

        foreach ($addOnLines as $line) {
            $addOnsCents += $line['total_cents'];
        }

        $operatorDefinitions = $this->operatorFees->definitionsFor($serviceId, $locationId);
        $priced = $this->pricing->price(
            $channel,
            $rate,
            $payer,
            $rules,
            $participants,
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
        $payload['tax_basis_points'] = $taxBasisPoints;
        $payload['priced_participants'] = $participants;
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

        return [
            'priced' => $priced,
            'currency' => $setting instanceof TenantSetting ? $setting->currency : 'USD',
            'disclosure' => $disclosure,
            'payload' => $payload,
        ];
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
