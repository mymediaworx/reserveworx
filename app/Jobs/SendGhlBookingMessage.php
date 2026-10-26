<?php

namespace App\Jobs;

use App\Services\Integrations\GhlMessages;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends one booking communication to the workspace's own GoHighLevel location.
 * The job stores ids only. The token stays on the connection row.
 */
class SendGhlBookingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 20;

    public function __construct(
        public string $tenantId,
        public string $logId,
    ) {
        $this->onConnection('database');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800];
    }

    public function handle(GhlMessages $messages): void
    {
        $messages->deliver($this->tenantId, $this->logId, $this->attempts());
    }

    public function failed(?Throwable $exception): void
    {
        app(GhlMessages::class)->markExhausted($this->tenantId, $this->logId, $exception);
    }
}
