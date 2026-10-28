<?php

namespace App\Services\Booking;

use App\Models\Departure;
use App\Models\DepartureMutation;
use App\Models\Tenant;
use App\Services\Allocation\IdempotencyConflictException;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Remembers a successful departure schedule or cancel so the same key returns
 * that response again. A request with no key still runs every time.
 */
final class DepartureMutationIdempotency
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(): JsonResponse  $callback
     */
    public function once(Request $request, Tenant $tenant, ?Departure $departure, string $action, array $payload, Closure $callback): JsonResponse
    {
        $key = $this->key($request);

        if ($key === null) {
            return $callback();
        }

        $hash = $this->hash($action, $departure?->id, $payload);
        $lock = substr(hash('sha256', $tenant->id.'|'.$action.'|'.$key), 0, 64);
        $acquired = DB::scalar('select get_lock(?, 10)', [$lock]);

        if ((int) $acquired !== 1) {
            return response()->json(['message' => 'That request is already running.'], 409);
        }

        try {
            return $this->perform($tenant, $departure, $action, $key, $hash, $callback);
        } catch (IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } finally {
            DB::scalar('select release_lock(?)', [$lock]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hash(string $action, ?string $departureId, array $payload): string
    {
        $encoded = json_encode([
            'action' => $action,
            'departure_id' => $departureId,
            'payload' => $this->sorted($payload),
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $encoded);
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function sorted(array $payload): array
    {
        $sorted = [];

        foreach ($payload as $key => $value) {
            $sorted[$key] = is_array($value) ? $this->sorted($value) : $value;
        }

        if (! array_is_list($payload)) {
            ksort($sorted);
        }

        return $sorted;
    }

    private function key(Request $request): ?string
    {
        $header = $request->headers->get('Idempotency-Key');
        $body = $request->input('idempotency_key');
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

    /**
     * @param  Closure(): JsonResponse  $callback
     */
    private function perform(Tenant $tenant, ?Departure $departure, string $action, string $key, string $hash, Closure $callback): JsonResponse
    {
        $existing = $this->stored($action, $key);

        if ($existing instanceof DepartureMutation) {
            if ($existing->http_status === null) {
                $existing->delete();
            } else {
                return $this->replay($existing, $departure, $hash);
            }
        }

        try {
            $pending = DepartureMutation::query()->create([
                'tenant_id' => $tenant->id,
                'departure_id' => $departure?->id,
                'action' => $action,
                'idempotency_key' => $key,
                'payload_hash' => $hash,
            ]);
        } catch (QueryException $exception) {
            $winner = $this->replayDuplicate($exception, $action, $key, $departure, $hash);

            if ($winner instanceof JsonResponse) {
                return $winner;
            }

            throw $exception;
        }

        try {
            $response = $callback();
        } catch (Throwable $exception) {
            $pending->delete();

            throw $exception;
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            $pending->delete();

            return $response;
        }

        $body = $response->getData(true);
        $pending->http_status = $status;
        $pending->response = is_array($body) ? $body : ['data' => $body];

        if ($pending->departure_id === null && is_array($body)) {
            $data = $body['data'] ?? null;
            $createdId = is_array($data) ? ($data['id'] ?? null) : null;

            if (is_string($createdId) && $createdId !== '') {
                $pending->departure_id = $createdId;
            }
        }

        $pending->save();

        return $response;
    }

    private function stored(string $action, string $key): ?DepartureMutation
    {
        $existing = DepartureMutation::query()
            ->where('action', $action)
            ->where('idempotency_key', $key)
            ->first();

        return $existing instanceof DepartureMutation ? $existing : null;
    }

    private function replay(DepartureMutation $existing, ?Departure $departure, string $hash): JsonResponse
    {
        if (($departure instanceof Departure && $existing->departure_id !== $departure->id) || ! hash_equals($existing->payload_hash, $hash)) {
            throw new IdempotencyConflictException;
        }

        if ($existing->http_status === null || ! is_array($existing->response)) {
            return response()->json(['message' => 'That request is already running.'], 409);
        }

        return response()->json($existing->response, $existing->http_status);
    }

    private function replayDuplicate(QueryException $exception, string $action, string $key, ?Departure $departure, string $hash): ?JsonResponse
    {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
            return null;
        }

        $existing = $this->stored($action, $key);

        if (! $existing instanceof DepartureMutation || $existing->http_status === null) {
            return null;
        }

        return $this->replay($existing, $departure, $hash);
    }
}
