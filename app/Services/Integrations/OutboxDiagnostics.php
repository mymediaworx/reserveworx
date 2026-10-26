<?php

namespace App\Services\Integrations;

use App\Models\GhlMessageLog;
use App\Models\GhlSyncLog;
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
            'stopped' => $this->stopped(),
        ];
    }

    /**
     * Contact syncs and messages that used their five tries and are still the latest try.
     *
     * @return list<array{id: string, type: string, kind: string, attempt: int, error: string}>
     */
    private function stopped(): array
    {
        $stopped = [];

        $syncs = GhlSyncLog::query()
            ->where('status', 'failed')
            ->where('attempt', '>=', 5)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        foreach ($syncs as $log) {
            if (count($stopped) >= 20) {
                break;
            }

            if (! is_string($log->booking_id) || $log->booking_id === '') {
                continue;
            }

            if (! $this->syncIsLatest($log)) {
                continue;
            }

            $stopped[] = [
                'id' => $log->id,
                'type' => 'sync',
                'kind' => $log->kind,
                'attempt' => $log->attempt,
                'error' => is_string($log->error) ? $log->error : '',
            ];
        }

        $messages = GhlMessageLog::query()
            ->where('status', 'failed')
            ->where('attempts', '>=', 5)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        foreach ($messages as $log) {
            if (count($stopped) >= 20) {
                break;
            }

            $stopped[] = [
                'id' => $log->id,
                'type' => 'message',
                'kind' => $log->kind,
                'attempt' => $log->attempts,
                'error' => is_string($log->error) ? $log->error : '',
            ];
        }

        return $stopped;
    }

    private function syncIsLatest(GhlSyncLog $log): bool
    {
        $query = GhlSyncLog::query()
            ->where('booking_id', $log->booking_id)
            ->where('kind', $log->kind);

        if ($log->payment_id === null) {
            $query->whereNull('payment_id');
        } else {
            $query->where('payment_id', $log->payment_id);
        }

        $latest = $query->orderByDesc('created_at')->orderByDesc('id')->first();

        return $latest instanceof GhlSyncLog && $latest->id === $log->id;
    }

    private function iso(mixed $instant): ?string
    {
        if (! $instant instanceof DateTimeInterface) {
            return null;
        }

        return CarbonImmutable::parse($instant)->utc()->toIso8601String();
    }
}
