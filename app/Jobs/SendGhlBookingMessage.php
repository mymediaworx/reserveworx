<?php

namespace App\Jobs;

use App\Services\Integrations\GhlMessages;
use App\Services\Integrations\GhlRateLimitPause;
use App\Services\Integrations\GhlRequestFailed;
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

    public int $rateLimitWaits = 0;

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
        try {
            $messages->deliver($this->tenantId, $this->logId, $this->attempts());
        } catch (GhlRequestFailed $exception) {
            $pause = new GhlRateLimitPause;
            $decision = $pause->decide($exception, $this->rateLimitWaits, $this->backoff());

            if ($decision === null) {
                throw $exception;
            }

            $messages->recordRateLimit(
                $this->tenantId,
                $this->logId,
                $decision['stopped'] ? null : $decision['seconds'],
            );

            if (! $decision['stopped']) {
                $this->rateLimitWaits++;
                $next = new self($this->tenantId, $this->logId);
                $next->rateLimitWaits = $this->rateLimitWaits;
                dispatch($next)->onConnection('database')->delay(now()->addSeconds($decision['seconds']));
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(GhlMessages::class)->markExhausted($this->tenantId, $this->logId, $exception);
    }
}
