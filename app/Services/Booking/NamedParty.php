<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Participant;
use App\Models\WaiverSignature;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Names the people on a booking. The staff API and the public review page
 * share these rules so a guest and a staff member cannot disagree.
 */
final class NamedParty
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function add(
        Booking $booking,
        string $name,
        bool $minor,
        mixed $guardianName,
        mixed $bodyKey,
        ?Request $request = null,
    ): Participant {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'A participant needs a name.']);
        }

        $guardian = $this->guardian($guardianName);

        if ($minor && $guardian === null) {
            throw ValidationException::withMessages([
                'guardian_name' => 'A minor needs a parent or guardian on their record.',
            ]);
        }

        $storedGuardian = $minor ? $guardian : null;
        $key = $this->idempotencyKey($request, $bodyKey);
        $hash = $this->payloadHash($booking->id, $name, $minor, $storedGuardian);

        try {
            $participant = $this->insert($booking, $name, $minor, $storedGuardian, $key, $hash);
        } catch (QueryException $exception) {
            $participant = $this->replayDuplicate($exception, $key, $hash, $booking->id);
        }

        if ($participant->wasRecentlyCreated) {
            $this->audit->log('participant.added', $participant, [
                'booking_id' => $participant->booking_id,
                'name' => $participant->name,
                'is_minor' => $participant->is_minor,
            ], request: $request);
        }

        return $participant;
    }

    /**
     * @return array{id: string, booking_id: string, name: string, is_minor: bool, guardian_name: string|null}
     */
    public function remove(Booking $booking, Participant $participant, ?Request $request = null): array
    {
        if ($participant->booking_id !== $booking->id) {
            throw (new ModelNotFoundException)->setModel(Participant::class, [$participant->id]);
        }

        if (WaiverSignature::query()->where('participant_id', $participant->id)->exists()) {
            throw new AllocationUnavailableException('A signed waiver keeps this participant on the booking.');
        }

        $payload = [
            'id' => $participant->id,
            'booking_id' => $participant->booking_id,
            'name' => $participant->name,
            'is_minor' => (bool) $participant->is_minor,
            'guardian_name' => $participant->guardian_name,
        ];
        $participant->delete();
        $this->audit->log('participant.removed', $participant, [
            'booking_id' => $booking->id,
            'name' => $payload['name'],
        ], request: $request);

        return $payload;
    }

    private function guardian(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $name = trim($value);

        return $name === '' ? null : $name;
    }

    private function idempotencyKey(?Request $request, mixed $body): ?string
    {
        $header = $request?->headers->get('Idempotency-Key');
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
        string $name,
        bool $minor,
        ?string $guardianName,
        ?string $key,
        string $hash,
    ): Participant {
        return DB::transaction(function () use ($booking, $name, $minor, $guardianName, $key, $hash): Participant {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->first();

            if (! $locked instanceof Booking) {
                throw (new ModelNotFoundException)->setModel(Booking::class, [$booking->id]);
            }

            if ($key !== null) {
                $existing = Participant::query()->where('idempotency_key', $key)->lockForUpdate()->first();

                if ($existing instanceof Participant) {
                    $this->assertSameRequest($existing, $locked->id, $hash);

                    return $existing;
                }
            }

            if (! in_array($locked->status, [BookingStatus::Held, BookingStatus::Confirmed], true)) {
                throw new AllocationUnavailableException('Only a held or confirmed booking can name a participant.');
            }

            $named = Participant::query()->where('booking_id', $locked->id)->lockForUpdate()->count();

            if ($named >= $locked->participants) {
                throw ValidationException::withMessages([
                    'name' => 'This booking already names everyone in the party.',
                ]);
            }

            return Participant::query()->create([
                'tenant_id' => $locked->tenant_id,
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
