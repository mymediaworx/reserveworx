<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Participant;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\Booking\BookingMutationIdempotency;
use App\Services\Booking\NamedParty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParticipantController extends Controller
{
    public function __construct(
        private readonly NamedParty $party,
        private readonly BookingMutationIdempotency $mutations,
    ) {}

    public function index(Booking $booking): JsonResponse
    {
        $this->authorize('view', $booking);
        $rows = [];

        foreach (Participant::query()->where('booking_id', $booking->id)->orderBy('id')->get() as $participant) {
            $rows[] = $this->payload($participant);
        }

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_minor' => ['required', 'boolean'],
            'guardian_name' => ['nullable', 'string', 'max:120'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $participant = $this->party->add(
                $booking,
                $validated['name'],
                (bool) $validated['is_minor'],
                $validated['guardian_name'] ?? null,
                $validated['idempotency_key'] ?? null,
                $request,
            );
        } catch (AllocationUnavailableException|IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(
            ['data' => $this->payload($participant)],
            $participant->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function destroy(Request $request, Booking $booking, Participant $participant): JsonResponse
    {
        $this->authorize('update', $booking);

        return $this->mutations->once($request, $booking, 'remove_participant', [
            'participant_id' => $participant->id,
        ], function () use ($request, $booking, $participant): JsonResponse {
            try {
                $payload = $this->party->remove($booking, $participant, $request);
            } catch (AllocationUnavailableException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            return response()->json(['data' => $payload]);
        });
    }

    /**
     * @return array{id: string, booking_id: string, name: string, is_minor: bool, guardian_name: string|null}
     */
    private function payload(Participant $participant): array
    {
        return [
            'id' => $participant->id,
            'booking_id' => $participant->booking_id,
            'name' => $participant->name,
            'is_minor' => $participant->is_minor,
            'guardian_name' => $participant->guardian_name,
        ];
    }
}
