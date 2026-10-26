<?php

namespace App\Services\Waivers;

use App\Models\Booking;
use App\Models\Participant;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Services\CheckIn\WaiverGate;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The people who still need the current waiver text, and the signatures they
 * store. The confirmation link and the review page both use this, so a guest
 * signs the same text the same way in either place.
 */
final class WaiverForm
{
    public function __construct(
        private readonly WaiverGate $gate,
        private readonly WaiverSigner $signer,
    ) {}

    public function blocking(Booking $booking): bool
    {
        $status = $this->gate->status($booking);

        return $status->required && ! $status->complete;
    }

    /**
     * @return array{required: bool, complete: bool, waivers: Collection<string, WaiverVersion>, people: list<array{key: string, participant: Participant|null, missing: list<WaiverVersion>}>}
     */
    public function present(Booking $booking): array
    {
        $waivers = $this->gate->current();
        $status = $this->gate->status($booking);

        return [
            'required' => $status->required,
            'complete' => $status->complete,
            'waivers' => $waivers,
            'people' => $this->people($booking, $waivers),
        ];
    }

    /**
     * Store the missing signatures. Returns false when nothing was left to sign.
     */
    public function sign(Request $request, Booking $booking): bool
    {
        $current = $this->gate->current();
        abort_if($current->isEmpty(), 404);
        $people = $this->people($booking, $current);

        if ($people === []) {
            return false;
        }

        $request->validate([
            'agree' => ['required', 'array'],
            'guardian' => ['array'],
            'guardian.*' => ['nullable', 'string', 'max:120'],
            'signer_name' => ['nullable', 'string', 'max:120'],
            'signer_minor' => ['nullable', 'boolean'],
        ], [
            'agree.required' => 'Tick the box to agree to the waiver.',
        ]);

        foreach ($people as $person) {
            $key = $person['key'];

            if (! (bool) $request->input('agree.'.$key)) {
                throw ValidationException::withMessages(['agree' => 'Each person needs to agree before signing.']);
            }

            $participant = $person['participant'];
            $name = $participant instanceof Participant ? $participant->name : trim((string) $request->input('signer_name'));
            $minor = $participant instanceof Participant ? $participant->is_minor : (bool) $request->boolean('signer_minor');
            $guardian = $participant instanceof Participant && is_string($participant->guardian_name) && trim($participant->guardian_name) !== ''
                ? $participant->guardian_name
                : trim((string) $request->input('guardian.'.$key));

            if ($name === '') {
                throw ValidationException::withMessages(['signer_name' => 'Enter the full name of the person taking part.']);
            }

            if ($minor && $guardian === '') {
                throw ValidationException::withMessages(['guardian' => 'A person under 18 needs a parent or guardian to sign. Enter their name.']);
            }

            foreach ($person['missing'] as $version) {
                $this->signer->sign(
                    $booking,
                    $version,
                    $participant,
                    $name,
                    $minor,
                    $minor ? $guardian : null,
                    $request->ip(),
                    $request->userAgent(),
                );
            }
        }

        return true;
    }

    /**
     * @param  Collection<string, WaiverVersion>  $current
     * @return list<array{key: string, participant: Participant|null, missing: list<WaiverVersion>}>
     */
    private function people(Booking $booking, Collection $current): array
    {
        if ($current->isEmpty()) {
            return [];
        }

        $participants = Participant::query()->where('booking_id', $booking->id)->orderBy('id')->get();
        $signatures = WaiverSignature::query()->where('booking_id', $booking->id)->get();

        if ($participants->isEmpty()) {
            $missing = $this->gate->unsigned($signatures, $current);

            return $missing === [] ? [] : [['key' => 'booking', 'participant' => null, 'missing' => $missing]];
        }

        $people = [];

        foreach ($participants as $participant) {
            $missing = $this->gate->unsigned($signatures, $current, $participant);

            if ($missing !== []) {
                $people[] = ['key' => $participant->id, 'participant' => $participant, 'missing' => $missing];
            }
        }

        return $people;
    }
}
