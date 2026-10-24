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
        $current = $this->current();

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
     * One line per named person. A booking with nobody named is a single line
     * for the booking. The label uses the same current-version rule as check-in.
     *
     * @param  Collection<int, Participant>  $participants
     * @param  Collection<int, WaiverSignature>  $signatures
     * @param  Collection<string, WaiverVersion>  $current
     * @return list<array{name: string, minor: bool, waiver: string}>
     */
    public function lines(Collection $participants, Collection $signatures, Collection $current): array
    {
        if ($current->isEmpty()) {
            if ($participants->isEmpty()) {
                return [['name' => 'This booking', 'minor' => false, 'waiver' => 'No waiver']];
            }

            $lines = [];

            foreach ($participants as $participant) {
                $lines[] = [
                    'name' => $participant->name,
                    'minor' => $participant->is_minor,
                    'waiver' => 'No waiver',
                ];
            }

            return $lines;
        }

        if ($participants->isEmpty()) {
            return [[
                'name' => 'This booking',
                'minor' => false,
                'waiver' => $this->waiverLabel($this->missingNames($current, function (WaiverVersion $version) use ($signatures): bool {
                    return $this->bookingSigned($signatures, $version);
                })),
            ]];
        }

        $lines = [];

        foreach ($participants as $participant) {
            $lines[] = [
                'name' => $participant->name,
                'minor' => $participant->is_minor,
                'waiver' => $this->waiverLabel($this->missingNames($current, function (WaiverVersion $version) use ($signatures, $participant): bool {
                    return $this->participantSigned($signatures, $version, $participant);
                })),
            ];
        }

        return $lines;
    }

    /**
     * @return Collection<string, WaiverVersion>
     */
    public function current(): Collection
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

    /**
     * @param  Collection<string, WaiverVersion>  $current
     * @param  callable(WaiverVersion): bool  $signed
     * @return list<string>
     */
    private function missingNames(Collection $current, callable $signed): array
    {
        $missing = [];

        foreach ($current as $version) {
            if (! $signed($version)) {
                $missing[] = $version->name;
            }
        }

        return $missing;
    }

    /**
     * @param  list<string>  $missing
     */
    private function waiverLabel(array $missing): string
    {
        return $missing === [] ? 'Signed' : 'Needs '.implode(', ', $missing);
    }

    private function guardianMissing(bool $minor, mixed $guardian): bool
    {
        if (! $minor) {
            return false;
        }

        return ! is_string($guardian) || trim($guardian) === '';
    }
}
