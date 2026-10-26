<?php

namespace App\Services\Waivers;

use App\Models\Booking;
use App\Models\Participant;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use Illuminate\Database\QueryException;

/**
 * Publishes waiver versions and stores signatures. The staff API, the staff
 * waivers page, and the customer signing page all write through here, so a
 * signature always copies the content hash of the exact text that was shown.
 */
final class WaiverSigner
{
    public function publish(string $tenantId, string $name, string $body): WaiverVersion
    {
        $name = trim($name);
        $latest = WaiverVersion::query()->where('name', $name)->orderByDesc('version')->first();

        return WaiverVersion::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'version' => $latest instanceof WaiverVersion ? $latest->version + 1 : 1,
            'body' => $body,
            'content_hash' => hash('sha256', $body),
        ]);
    }

    /**
     * A named participant signs once per version; a repeat returns the first
     * signature. Without a participant the signature covers the booking.
     */
    public function sign(
        Booking $booking,
        WaiverVersion $waiver,
        ?Participant $participant,
        string $name,
        bool $minor,
        ?string $guardian,
        ?string $ip,
        ?string $userAgent,
    ): WaiverSignature {
        if ($participant instanceof Participant) {
            $existing = $this->existing($booking, $waiver, $participant);

            if ($existing instanceof WaiverSignature) {
                return $existing;
            }
        }

        try {
            return WaiverSignature::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'participant_id' => $participant?->id,
                'waiver_version_id' => $waiver->id,
                'participant_name' => $name,
                'is_minor' => $minor,
                'guardian_name' => $minor ? $guardian : null,
                'ip_address' => $ip,
                'user_agent' => mb_substr((string) $userAgent, 0, 255),
                'content_hash' => $waiver->content_hash,
                'signed_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! $participant instanceof Participant || (int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            $existing = $this->existing($booking, $waiver, $participant);

            if (! $existing instanceof WaiverSignature) {
                throw $exception;
            }

            return $existing;
        }
    }

    public function existing(Booking $booking, WaiverVersion $waiver, Participant $participant): ?WaiverSignature
    {
        return WaiverSignature::query()
            ->where('booking_id', $booking->id)
            ->where('waiver_version_id', $waiver->id)
            ->where('participant_id', $participant->id)
            ->first();
    }
}
