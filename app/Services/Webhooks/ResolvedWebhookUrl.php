<?php

namespace App\Services\Webhooks;

final readonly class ResolvedWebhookUrl
{
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public ?string $address,
    ) {}
}
