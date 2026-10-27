<?php

namespace App\Services\Waivers;

use App\Models\Booking;
use App\Models\Participant;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
     * A named participant signs once per version. A booking with nobody named
     * keeps one signature per version. A repeat returns that first row and
     * does not replace the stored name, hash, guardian, IP address, or browser.
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
            return $this->signParticipant($booking, $waiver, $participant, $name, $minor, $guardian, $ip, $userAgent);
        }

        return DB::transaction(function () use ($booking, $waiver, $name, $minor, $guardian, $ip, $userAgent): WaiverSignature {
            Booking::query()->whereKey($booking->id)->lockForUpdate()->first();
            $existing = $this->bookingSignature($booking, $waiver);

            if ($existing instanceof WaiverSignature) {
                return $existing;
            }

            return $this->store($booking, $waiver, null, $name, $minor, $guardian, $ip, $userAgent);
        });
    }

    private function signParticipant(
        Booking $booking,
        WaiverVersion $waiver,
        Participant $participant,
        string $name,
        bool $minor,
        ?string $guardian,
        ?string $ip,
        ?string $userAgent,
    ): WaiverSignature {
        $existing = $this->existing($booking, $waiver, $participant);

        if ($existing instanceof WaiverSignature) {
            return $existing;
        }

        try {
            return $this->store($booking, $waiver, $participant, $name, $minor, $guardian, $ip, $userAgent);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            $existing = $this->existing($booking, $waiver, $participant);

            if (! $existing instanceof WaiverSignature) {
                throw $exception;
            }

            return $existing;
        }
    }

    private function bookingSignature(Booking $booking, WaiverVersion $waiver): ?WaiverSignature
    {
        $existing = WaiverSignature::query()
            ->where('booking_id', $booking->id)
            ->where('waiver_version_id', $waiver->id)
            ->whereNull('participant_id')
            ->orderBy('signed_at')
            ->orderBy('id')
            ->first();

        return $existing instanceof WaiverSignature ? $existing : null;
    }

    private function store(
        Booking $booking,
        WaiverVersion $waiver,
        ?Participant $participant,
        string $name,
        bool $minor,
        ?string $guardian,
        ?string $ip,
        ?string $userAgent,
    ): WaiverSignature {
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
