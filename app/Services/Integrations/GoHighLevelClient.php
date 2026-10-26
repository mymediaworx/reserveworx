<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class GoHighLevelClient
{
    public const VERSION = '2021-07-28';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $version,
    ) {}

    /**
     * @param  array{
     *     email?: string,
     *     phone?: string,
     *     name?: string,
     *     firstName?: string,
     *     lastName?: string
     * }  $contact
     */
    public function upsertContact(string $token, string $locationId, array $contact): string
    {
        $payload = $this->contactPayload($contact);
        $payload['locationId'] = $locationId;
        $payload['createNewIfDuplicateAllowed'] = false;

        $response = $this->send('POST', $token, '/contacts/upsert', $payload, 'upsert');
        $id = $this->contactId($response, $locationId);

        if ($id === null) {
            throw new GhlRequestFailed($response->status(), 'upsert', true);
        }

        return $id;
    }

    /**
     * @param  array{
     *     email?: string,
     *     phone?: string,
     *     name?: string,
     *     firstName?: string,
     *     lastName?: string
     * }  $contact
     */
    public function updateContact(string $token, string $contactId, string $locationId, array $contact): void
    {
        $response = $this->send(
            'PUT',
            $token,
            '/contacts/'.rawurlencode($contactId),
            $this->contactPayload($contact),
            'update',
        );
        $payload = $response->json();

        if (! is_array($payload)) {
            return;
        }

        $body = $payload['contact'] ?? null;

        if (! is_array($body)) {
            return;
        }

        $returnedLocation = $body['locationId'] ?? null;
        $returnedId = $body['id'] ?? null;
        $locationMismatch = is_string($returnedLocation) && $returnedLocation !== '' && $returnedLocation !== $locationId;
        $idMismatch = is_string($returnedId) && $returnedId !== '' && $returnedId !== $contactId;

        if ($locationMismatch || $idMismatch) {
            throw new GhlRequestFailed($response->status(), 'update', true, true);
        }
    }

    /**
     * @param  list<string>  $tags
     */
    public function addTags(string $token, string $contactId, array $tags): void
    {
        $this->send('POST', $token, '/contacts/'.rawurlencode($contactId).'/tags', [
            'tags' => $tags,
        ], 'tags');
    }

    public function addNote(string $token, string $contactId, string $body): void
    {
        $this->send('POST', $token, '/contacts/'.rawurlencode($contactId).'/notes', [
            'body' => $body,
        ], 'note');
    }

    /**
     * @param  array{type: string, contactId: string, message: string, status: string, subject?: string, emailTo?: string, toNumber?: string}  $message
     */
    public function sendMessage(string $token, array $message): string
    {
        $response = $this->send('POST', $token, '/conversations/messages', $message, 'message');
        $payload = $response->json();
        $id = is_array($payload) ? ($payload['messageId'] ?? null) : null;

        if (! is_string($id) || $id === '') {
            throw new GhlRequestFailed($response->status(), 'message', true);
        }

        return $id;
    }

    /**
     * Confirms the token can read the location and returns its name when
     * GoHighLevel sends one.
     */
    public function assertLocation(string $token, string $locationId): ?string
    {
        $response = $this->send('GET', $token, '/locations/'.rawurlencode($locationId), null, 'location');
        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        $id = null;

        if (isset($payload['location']) && is_array($payload['location']) && isset($payload['location']['id']) && is_string($payload['location']['id'])) {
            $id = $payload['location']['id'];
        } elseif (isset($payload['id']) && is_string($payload['id'])) {
            $id = $payload['id'];
        }

        if ($id !== null && $id !== $locationId) {
            throw new GhlRequestFailed($response->status(), 'location', true, true);
        }

        $location = isset($payload['location']) && is_array($payload['location']) ? $payload['location'] : $payload;
        $name = $location['name'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function send(string $method, string $token, string $path, ?array $body, string $kind): Response
    {
        $pending = Http::withToken($token)
            ->withHeaders([
                'Version' => $this->version,
                'Accept' => 'application/json',
            ])
            ->timeout(15)
            ->connectTimeout(5)
            ->withOptions(['allow_redirects' => false]);

        $url = $this->baseUrl.'/'.ltrim($path, '/');

        try {
            $response = $body === null
                ? $pending->send($method, $url)
                : $pending->asJson()->send($method, $url, ['json' => $body]);
        } catch (Throwable) {
            throw new GhlRequestFailed(null, $kind, true);
        }

        if (! $response->successful()) {
            throw new GhlRequestFailed($response->status(), $kind);
        }

        return $response;
    }

    /**
     * @param  array{
     *     email?: string,
     *     phone?: string,
     *     name?: string,
     *     firstName?: string,
     *     lastName?: string
     * }  $contact
     * @return array{source: string, email?: string, phone?: string, name?: string, firstName?: string, lastName?: string}
     */
    private function contactPayload(array $contact): array
    {
        $payload = ['source' => 'ReserveWorx'];

        foreach (['email', 'phone', 'name', 'firstName', 'lastName'] as $key) {
            if (isset($contact[$key]) && $contact[$key] !== '') {
                $payload[$key] = $contact[$key];
            }
        }

        return $payload;
    }

    private function contactId(Response $response, string $locationId): ?string
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        $contact = $payload['contact'] ?? null;

        if (! is_array($contact)) {
            return null;
        }

        $id = $contact['id'] ?? null;
        $returnedLocation = $contact['locationId'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        if (is_string($returnedLocation) && $returnedLocation !== '' && $returnedLocation !== $locationId) {
            return null;
        }

        return $id;
    }
}
