<?php

namespace App\Domain\Fees\Disclosures;

use App\Enums\DisclosureKind;
use App\Enums\FeePayer;
use App\Models\DisclosureVersion;
use App\Models\Tenant;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Returns the current booking fee refund terms for the tenant in context,
 * publishing a new immutable version only when the generated text changes.
 */
final class DisclosurePublisher
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly BookingFeeRefundTerms $terms,
    ) {}

    /**
     * Null for operator-absorbed tenants: the customer sees no booking fee, so there are no fee refund terms.
     */
    public function currentBookingFeeRefundTerms(Tenant $tenant, FeePayer $payer): ?DisclosureVersion
    {
        if ($this->tenants->id() !== $tenant->id) {
            throw new TenantContextException('Disclosures can only be published inside the same tenant context.');
        }

        if ($payer !== FeePayer::CustomerPaid) {
            return null;
        }

        $kind = DisclosureKind::BookingFeeRefundTerms;
        $body = $this->terms->body((string) $tenant->name);
        $hash = hash('sha256', BookingFeeRefundTerms::RULES_VERSION."\n".$payer->value."\n".$body);

        return DB::transaction(function () use ($kind, $payer, $body, $hash): DisclosureVersion {
            $latest = DisclosureVersion::query()
                ->ofKind($kind)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            if ($latest instanceof DisclosureVersion && $latest->content_hash === $hash) {
                return $latest;
            }

            $nextVersion = $latest instanceof DisclosureVersion ? $latest->version + 1 : 1;

            return DisclosureVersion::create([
                'kind' => $kind,
                'version' => $nextVersion,
                'fee_payer' => $payer,
                'rules_version' => BookingFeeRefundTerms::RULES_VERSION,
                'body' => $body,
                'content_hash' => $hash,
                'published_at' => now(),
            ]);
        });
    }
}
