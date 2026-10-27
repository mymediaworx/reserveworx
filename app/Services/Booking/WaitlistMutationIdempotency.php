<?php

namespace App\Services\Booking;

use App\Models\WaitlistEntry;
use App\Models\WaitlistMutation;
use App\Services\Allocation\IdempotencyConflictException;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Remembers a successful waitlist cancellation so the same key returns that
 * response again. Joining the list still uses the key stored on the entry.
 */
final class WaitlistMutationIdempotency
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(): JsonResponse  $callback
     */
    public function once(Request $request, WaitlistEntry $entry, string $action, array $payload, Closure $callback): JsonResponse
    {
        $key = $this->key($request);

        if ($key === null) {
            return $callback();
        }

        $hash = $this->hash($action, $entry->id, $payload);
        $lock = substr(hash('sha256', $entry->tenant_id.'|'.$action.'|'.$key), 0, 64);
        $acquired = DB::scalar('select get_lock(?, 10)', [$lock]);

        if ((int) $acquired !== 1) {
            return response()->json(['message' => 'That request is already running.'], 409);
        }

        try {
            return $this->perform($entry, $action, $key, $hash, $callback);
        } catch (IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } finally {
            DB::scalar('select release_lock(?)', [$lock]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hash(string $action, string $entryId, array $payload): string
    {
        $encoded = json_encode([
            'action' => $action,
            'waitlist_entry_id' => $entryId,
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
    private function perform(WaitlistEntry $entry, string $action, string $key, string $hash, Closure $callback): JsonResponse
    {
        $existing = $this->stored($action, $key);

        if ($existing instanceof WaitlistMutation) {
            if ($existing->http_status === null) {
                $existing->delete();
            } else {
                return $this->replay($existing, $entry, $hash);
            }
        }

        try {
            $pending = WaitlistMutation::query()->create([
                'tenant_id' => $entry->tenant_id,
                'waitlist_entry_id' => $entry->id,
                'action' => $action,
                'idempotency_key' => $key,
                'payload_hash' => $hash,
            ]);
        } catch (QueryException $exception) {
            $winner = $this->replayDuplicate($exception, $action, $key, $entry, $hash);

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
        $pending->save();

        return $response;
    }

    private function stored(string $action, string $key): ?WaitlistMutation
    {
        $existing = WaitlistMutation::query()
            ->where('action', $action)
            ->where('idempotency_key', $key)
            ->first();

        return $existing instanceof WaitlistMutation ? $existing : null;
    }

    private function replay(WaitlistMutation $existing, WaitlistEntry $entry, string $hash): JsonResponse
    {
        if ($existing->waitlist_entry_id !== $entry->id || ! hash_equals($existing->payload_hash, $hash)) {
            throw new IdempotencyConflictException;
        }

        if ($existing->http_status === null || ! is_array($existing->response)) {
            return response()->json(['message' => 'That request is already running.'], 409);
        }

        return response()->json($existing->response, $existing->http_status);
    }

    private function replayDuplicate(QueryException $exception, string $action, string $key, WaitlistEntry $entry, string $hash): ?JsonResponse
    {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
            return null;
        }

        $existing = $this->stored($action, $key);

        if (! $existing instanceof WaitlistMutation || $existing->http_status === null) {
            return null;
        }

        return $this->replay($existing, $entry, $hash);
    }
}
