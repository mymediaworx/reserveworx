<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\WaiverSignature;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ParticipantController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

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
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_minor' => ['required', 'boolean'],
            'guardian_name' => ['nullable', 'string', 'max:120'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);
        $name = trim($validated['name']);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'A participant needs a name.']);
        }

        $minor = (bool) $validated['is_minor'];
        $guardian = $this->guardian($validated['guardian_name'] ?? null);

        if ($minor && $guardian === null) {
            throw ValidationException::withMessages(['guardian_name' => 'A minor needs a parent or guardian on their record.']);
        }

        $guardianName = $minor ? $guardian : null;
        $key = $this->idempotencyKey($request, $validated['idempotency_key'] ?? null);
        $hash = $this->payloadHash($booking->id, $name, $minor, $guardianName);

        try {
            $participant = $this->insert($booking, $tenant, $name, $minor, $guardianName, $key, $hash);
        } catch (IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (QueryException $exception) {
            try {
                $participant = $this->replayDuplicate($exception, $key, $hash, $booking->id);
            } catch (IdempotencyConflictException $conflict) {
                return response()->json(['message' => $conflict->getMessage()], 409);
            }
        }

        if ($participant->wasRecentlyCreated) {
            $this->audit->log('participant.added', $participant, [
                'booking_id' => $participant->booking_id,
                'name' => $participant->name,
                'is_minor' => $participant->is_minor,
            ], request: $request);
        }

        return response()->json(
            ['data' => $this->payload($participant)],
            $participant->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function destroy(Request $request, Booking $booking, Participant $participant): JsonResponse
    {
        $this->authorize('update', $booking);

        if ($participant->booking_id !== $booking->id) {
            abort(404);
        }

        if (WaiverSignature::query()->where('participant_id', $participant->id)->exists()) {
            return response()->json([
                'message' => 'A signed waiver keeps this participant on the booking.',
            ], 409);
        }

        $payload = $this->payload($participant);
        $participant->delete();
        $this->audit->log('participant.removed', $participant, [
            'booking_id' => $booking->id,
            'name' => $payload['name'],
        ], request: $request);

        return response()->json(['data' => $payload]);
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

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    private function guardian(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $name = trim($value);

        return $name === '' ? null : $name;
    }

    private function idempotencyKey(Request $request, mixed $body): ?string
    {
        $header = $request->headers->get('Idempotency-Key');
        $key = is_string($header) && $header !== '' ? $header : (is_string($body) && $body !== '' ? $body : null);

        if ($key === null) {
            return null;
        }

        if (strlen($key) > 80) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'An idempotency key must be 80 characters or fewer.',
            ]);
        }

        return $key;
    }

    private function insert(
        Booking $booking,
        Tenant $tenant,
        string $name,
        bool $minor,
        ?string $guardianName,
        ?string $key,
        string $hash,
    ): Participant {
        return DB::transaction(function () use ($booking, $tenant, $name, $minor, $guardianName, $key, $hash): Participant {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->first();

            if (! $locked instanceof Booking) {
                abort(404);
            }

            if ($key !== null) {
                $existing = Participant::query()->where('idempotency_key', $key)->lockForUpdate()->first();

                if ($existing instanceof Participant) {
                    $this->assertSameRequest($existing, $locked->id, $hash);

                    return $existing;
                }
            }

            $named = Participant::query()->where('booking_id', $locked->id)->lockForUpdate()->count();

            if ($named >= $locked->participants) {
                throw ValidationException::withMessages([
                    'name' => 'This booking already names everyone in the party.',
                ]);
            }

            return Participant::query()->create([
                'tenant_id' => $tenant->id,
                'booking_id' => $locked->id,
                'name' => $name,
                'is_minor' => $minor,
                'guardian_name' => $guardianName,
                'idempotency_key' => $key,
                'payload_hash' => $hash,
            ]);
        });
    }

    private function payloadHash(string $bookingId, string $name, bool $minor, ?string $guardian): string
    {
        return hash('sha256', implode("\n", [
            $bookingId,
            $name,
            $minor ? '1' : '0',
            $guardian ?? '',
        ]));
    }

    private function assertSameRequest(Participant $existing, string $bookingId, string $hash): void
    {
        if ($existing->booking_id !== $bookingId || ! hash_equals($existing->payload_hash, $hash)) {
            throw new IdempotencyConflictException;
        }
    }

    private function replayDuplicate(QueryException $exception, ?string $key, string $hash, string $bookingId): Participant
    {
        if ($key === null || (int) ($exception->errorInfo[1] ?? 0) !== 1062) {
            throw $exception;
        }

        $existing = Participant::query()->where('idempotency_key', $key)->first();

        if (! $existing instanceof Participant) {
            throw $exception;
        }

        $this->assertSameRequest($existing, $bookingId, $hash);

        return $existing;
    }
}
