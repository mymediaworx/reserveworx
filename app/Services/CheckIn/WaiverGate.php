<?php

namespace App\Services\CheckIn;

use App\Models\Booking;
use App\Models\Participant;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use Illuminate\Support\Collection;

/**
 * The current waiver for each name is the highest version in this workspace.
 * A booking with named people needs each person on that version. A minor's signature
 * needs the guardian name copied onto it. A booking with no named people needs one
 * signature on the booking for that version.
 */
final class WaiverGate
{
    public function status(Booking $booking): WaiverReadiness
    {
        $current = $this->currentVersions();

        if ($current->isEmpty()) {
            return new WaiverReadiness(required: false, complete: true, missing: []);
        }

        $participants = Participant::query()->where('booking_id', $booking->id)->orderBy('id')->get();
        $signatures = WaiverSignature::query()->where('booking_id', $booking->id)->get();
        $missing = [];

        foreach ($current as $version) {
            if ($participants->isEmpty()) {
                if (! $this->bookingSigned($signatures, $version)) {
                    $missing[] = ['who' => 'This booking', 'waiver' => $version->name];
                }

                continue;
            }

            foreach ($participants as $participant) {
                if (! $this->participantSigned($signatures, $version, $participant)) {
                    $missing[] = ['who' => $participant->name, 'waiver' => $version->name];
                }
            }
        }

        return new WaiverReadiness(
            required: true,
            complete: $missing === [],
            missing: $missing,
        );
    }

    /**
     * @return Collection<string, WaiverVersion>
     */
    private function currentVersions(): Collection
    {
        $current = [];
        $versions = WaiverVersion::query()->orderBy('name')->orderByDesc('version')->get();

        foreach ($versions as $version) {
            if (! array_key_exists($version->name, $current)) {
                $current[$version->name] = $version;
            }
        }

        return new Collection($current);
    }

    /**
     * @param  Collection<int, WaiverSignature>  $signatures
     */
    private function bookingSigned(Collection $signatures, WaiverVersion $version): bool
    {
        foreach ($signatures as $signature) {
            if ($signature->waiver_version_id !== $version->id) {
                continue;
            }

            if ($this->guardianMissing($signature->is_minor, $signature->guardian_name)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param  Collection<int, WaiverSignature>  $signatures
     */
    private function participantSigned(Collection $signatures, WaiverVersion $version, Participant $participant): bool
    {
        foreach ($signatures as $signature) {
            if ($signature->waiver_version_id !== $version->id || $signature->participant_id !== $participant->id) {
                continue;
            }

            $minor = $participant->is_minor || $signature->is_minor;

            if ($this->guardianMissing($minor, $signature->guardian_name)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function guardianMissing(bool $minor, mixed $guardian): bool
    {
        if (! $minor) {
            return false;
        }

        return ! is_string($guardian) || trim($guardian) === '';
    }
}
