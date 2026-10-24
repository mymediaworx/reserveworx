<?php

namespace App\Services\Pricing;

use App\Models\Booking;
use App\Models\PricingSnapshot;
use App\Models\PromoCode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Looks up a promo code for a booking date and records one use when that
 * booking is confirmed. Applying a code does not increment the count.
 */
final class PromoCodes
{
    public function resolve(string $promoCode, CarbonImmutable $on): PromoCode
    {
        $code = strtoupper(trim($promoCode));

        if ($code === '') {
            throw new InvalidArgumentException('Enter a promo code.');
        }

        $promo = PromoCode::query()->where('code', $code)->first();

        if (! $promo instanceof PromoCode || ! $promo->active) {
            throw new InvalidArgumentException('That promo code is not valid.');
        }

        $day = $on->toDateString();
        $starts = $promo->starts_on;
        $ends = $promo->ends_on;

        if ($starts instanceof DateTimeInterface && $day < $starts->format('Y-m-d')) {
            throw new InvalidArgumentException('That promo code is not valid yet.');
        }

        if ($ends instanceof DateTimeInterface && $day > $ends->format('Y-m-d')) {
            throw new InvalidArgumentException('That promo code has expired.');
        }

        if ($promo->usage_limit !== null && $promo->redeemed_count >= $promo->usage_limit) {
            throw new InvalidArgumentException('That promo code has been used up.');
        }

        return $promo;
    }

    /**
     * Count this booking's promo once. The caller is the confirm transaction,
     * which runs only while the booking is still held.
     */
    public function record(Booking $booking): void
    {
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if (! $snapshot instanceof PricingSnapshot) {
            return;
        }

        $code = $snapshot->payload['promo_code'] ?? null;

        if (! is_string($code) || $code === '') {
            return;
        }

        $promo = PromoCode::query()->where('code', $code)->lockForUpdate()->first();

        if (! $promo instanceof PromoCode) {
            return;
        }

        $promo->redeemed_count = $promo->redeemed_count + 1;
        $promo->save();
    }
}
