<?php

namespace App\Http\Controllers\Web;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Participant;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Services\Branding\CurrentWorkspaceBranding;
use App\Services\CheckIn\WaiverGate;
use App\Services\Waivers\WaiverSigner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The customer's signed waiver page (#134). It lists the workspace's current
 * waiver versions in full and stores one signature per named person, or one
 * for the booking when nobody is named, through the same path as the API.
 */
class WaiverSigningController extends Controller
{
    public function __construct(
        private readonly WaiverGate $gate,
        private readonly WaiverSigner $signer,
    ) {}

    public function show(Booking $booking): View
    {
        $this->assertSignable($booking);
        $current = $this->gate->current();

        return view('waivers.sign', [
            'booking' => $booking->loadMissing(['service', 'location']),
            'businessName' => $this->businessName($booking),
            'waivers' => $current,
            'readiness' => $this->gate->status($booking),
            'people' => $this->people($booking, $current),
        ]);
    }

    public function store(Request $request, Booking $booking): RedirectResponse
    {
        $this->assertSignable($booking);
        $current = $this->gate->current();
        abort_if($current->isEmpty(), 404);
        $people = $this->people($booking, $current);

        if ($people === []) {
            return redirect()->to($request->fullUrl());
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

        return redirect()->to($request->fullUrl())->with('status', 'Signed. Thank you.');
    }

    /**
     * People who still need to sign, each with the current versions they lack.
     *
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

    private function assertSignable(Booking $booking): void
    {
        abort_unless(in_array($booking->status, [BookingStatus::Held, BookingStatus::Confirmed], true), 404);
        abort_if($booking->ends_at->lessThan(now()->subDay()), 404);
    }

    private function businessName(Booking $booking): string
    {
        $branding = app(CurrentWorkspaceBranding::class)->resolve();

        return $branding->displayName !== '' ? $branding->displayName : (string) $booking->tenant?->name;
    }
}
