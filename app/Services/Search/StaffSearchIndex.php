<?php

namespace App\Services\Search;

use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Pool;
use App\Models\StaffSearchToken;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Prefix tokens for staff quick search. Each stored value is a suffix of a
 * word, so a search for the start of that suffix uses the token index.
 */
final class StaffSearchIndex
{
    public const BOOKING = 'booking';

    public const CUSTOMER = 'customer';

    public const ASSET = 'asset';

    public const POOL = 'pool';

    private const TOKEN_CAP = 100;

    private const SUFFIX_STARTS = 24;

    private static ?bool $ready = null;

    public function backfill(): void
    {
        Customer::query()->withoutGlobalScopes()->orderBy('id')->chunkById(200, function ($customers): void {
            foreach ($customers as $customer) {
                $this->syncCustomer($customer, false);
            }
        });

        Booking::query()->withoutGlobalScopes()->orderBy('id')->chunkById(200, function ($bookings): void {
            foreach ($bookings as $booking) {
                $this->syncBooking($booking);
            }
        });

        Asset::query()->withoutGlobalScopes()->whereNull('deleted_at')->orderBy('id')->chunkById(200, function ($assets): void {
            foreach ($assets as $asset) {
                $this->syncAsset($asset);
            }
        });

        Pool::query()->withoutGlobalScopes()->whereNull('deleted_at')->orderBy('id')->chunkById(200, function ($pools): void {
            foreach ($pools as $pool) {
                $this->syncPool($pool);
            }
        });
    }

    public function syncCustomer(Customer $customer, bool $bookings = true): void
    {
        if (! $this->ready()) {
            return;
        }

        if ($customer->anonymized_at !== null) {
            $this->forget(self::CUSTOMER, $customer->tenant_id, $customer->id);
        } else {
            $this->replace(self::CUSTOMER, $customer->tenant_id, $customer->id, null, [
                $customer->name,
                $customer->email,
                $customer->phone,
            ]);
        }

        if ($bookings && ! $customer->wasRecentlyCreated && $customer->wasChanged(['name', 'email', 'phone', 'anonymized_at'])) {
            $this->syncBookingsForCustomer($customer);
        }
    }

    public function forgetCustomerOnBookings(Customer $customer): void
    {
        if (! $this->ready()) {
            return;
        }

        Booking::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $customer->tenant_id)
            ->where('customer_id', $customer->id)
            ->orderBy('id')
            ->chunkById(200, function ($bookings): void {
                foreach ($bookings as $booking) {
                    $this->syncBooking($booking, false);
                }
            });
    }

    public function syncBooking(Booking $booking, bool $includeCustomer = true): void
    {
        if (! $this->ready()) {
            return;
        }

        $parts = [$booking->id];

        if ($includeCustomer && is_string($booking->customer_id) && $booking->customer_id !== '') {
            $customer = Customer::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $booking->tenant_id)
                ->where('id', $booking->customer_id)
                ->first();

            if ($customer instanceof Customer && $customer->anonymized_at === null) {
                $parts[] = $customer->name;
                $parts[] = $customer->email;
                $parts[] = $customer->phone;
            }
        }

        $this->replace(self::BOOKING, $booking->tenant_id, $booking->id, $booking->location_id, $parts);
    }

    public function syncAsset(Asset $asset): void
    {
        if ($asset->trashed()) {
            $this->forget(self::ASSET, $asset->tenant_id, $asset->id);

            return;
        }

        $this->replace(self::ASSET, $asset->tenant_id, $asset->id, $asset->location_id, [$asset->name]);
    }

    public function syncPool(Pool $pool): void
    {
        if ($pool->trashed()) {
            $this->forget(self::POOL, $pool->tenant_id, $pool->id);

            return;
        }

        $this->replace(self::POOL, $pool->tenant_id, $pool->id, $pool->location_id, [$pool->name]);
    }

    public function forget(string $type, string $tenantId, string $subjectId): void
    {
        if (! $this->ready()) {
            return;
        }

        StaffSearchToken::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('subject_type', $type)
            ->where('subject_id', $subjectId)
            ->delete();
    }

    /**
     * Words and digit runs the staff member typed, longest first.
     *
     * @return list<string>
     */
    public function terms(string $raw): array
    {
        $raw = mb_strtolower(trim(mb_substr($raw, 0, 80)));
        $words = preg_split('/[^\p{L}\p{N}]+/u', $raw) ?: [];
        $terms = [];

        foreach ($words as $word) {
            if (mb_strlen($word) >= 2) {
                $terms[] = mb_substr($word, 0, 80);
            }
        }

        $terms = array_values(array_unique($terms));
        usort($terms, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        return array_slice($terms, 0, 5);
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function replace(string $type, string $tenantId, string $subjectId, ?string $locationId, array $parts): void
    {
        if (! $this->ready()) {
            return;
        }

        $this->forget($type, $tenantId, $subjectId);

        $tokens = $this->tokens($parts);

        if ($tokens === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($tokens as $token) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'subject_type' => $type,
                'subject_id' => $subjectId,
                'location_id' => $locationId,
                'token' => $token,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            StaffSearchToken::query()->insert($chunk);
        }
    }

    /**
     * @param  list<string|null>  $parts
     * @return list<string>
     */
    private function tokens(array $parts): array
    {
        $found = [];

        foreach ($parts as $part) {
            if (! is_string($part) || trim($part) === '') {
                continue;
            }

            $lower = mb_strtolower($part);
            $this->addWord($found, preg_replace('/[^\p{L}\p{N}]+/u', '', $lower) ?? '');

            foreach (preg_split('/[^\p{L}\p{N}]+/u', $lower) ?: [] as $word) {
                $this->addWord($found, $word);
            }

            $this->addWord($found, preg_replace('/\D+/', '', $lower) ?? '');

            if (count($found) >= self::TOKEN_CAP) {
                break;
            }
        }

        return array_slice(array_keys($found), 0, self::TOKEN_CAP);
    }

    /**
     * @param  array<string, true>  $found
     */
    private function addWord(array &$found, string $word): void
    {
        $word = mb_substr($word, 0, 80);
        $length = mb_strlen($word);

        if ($length < 3 || count($found) >= self::TOKEN_CAP) {
            return;
        }

        $last = min($length - 3, self::SUFFIX_STARTS - 1);

        for ($start = 0; $start <= $last; $start++) {
            $found[mb_substr($word, $start, 80)] = true;

            if (count($found) >= self::TOKEN_CAP) {
                return;
            }
        }
    }

    private function syncBookingsForCustomer(Customer $customer): void
    {
        Booking::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $customer->tenant_id)
            ->where('customer_id', $customer->id)
            ->orderBy('id')
            ->chunkById(200, function ($bookings): void {
                foreach ($bookings as $booking) {
                    $this->syncBooking($booking);
                }
            });
    }

    private function ready(): bool
    {
        if (self::$ready === true) {
            return true;
        }

        return self::$ready = Schema::hasTable('staff_search_tokens');
    }
}
