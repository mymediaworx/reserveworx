<?php

namespace App\Services\Integrations;

use App\Models\IntegrationLog;
use App\Models\IntegrationOutbox;
use App\Models\NotificationHook;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class OutboxDiagnostics
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $rows = IntegrationOutbox::query()->orderByDesc('created_at')->limit(20)->get();
        $logs = IntegrationLog::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return [
            'queued' => IntegrationOutbox::query()->where('status', 'queued')->count(),
            'held' => IntegrationOutbox::query()->where('status', 'held')->count(),
            'notifications_skipped' => NotificationHook::query()->where('delivery_status', 'skipped')->count(),
            'events' => $rows->map(fn (IntegrationOutbox $row): array => [
                'id' => $row->id,
                'kind' => $row->kind,
                'status' => $row->status,
                'attempts' => $row->attempts,
                'last_error' => $row->last_error,
                'available_at' => $this->iso($row->available_at),
            ])->all(),
            'logs' => $logs->map(fn (IntegrationLog $log): array => [
                'id' => $log->id,
                'channel' => $log->channel,
                'channel_label' => $log->channelLabel(),
                'kind' => $log->kind,
                'attempt' => $log->attempt,
                'status' => $log->status,
                'http_status' => $log->http_status,
                'error' => $log->error,
                'available_at' => $this->iso($log->available_at),
                'created_at' => $this->iso($log->created_at),
            ])->all(),
        ];
    }

    private function iso(mixed $instant): ?string
    {
        if (! $instant instanceof DateTimeInterface) {
            return null;
        }

        return CarbonImmutable::parse($instant)->utc()->toIso8601String();
    }
}
