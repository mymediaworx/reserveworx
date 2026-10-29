<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\User;
use App\Support\Display;
use App\Tenancy\StaffLocationAccess;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Read-only audit log for the current workspace. Spec Section 12.
 * Row clocks use the first location zone with its abbreviation (Spec Section 2.4).
 * The same rows as GET /api/v1/audit-logs. This page does not write one.
 */
#[Layout('layouts.staff')]
#[Title('Audit log')]
class ActivityLog extends Component
{
    use WithinTenant;

    public const LIMIT = 50;

    /**
     * Keys the logger already drops, checked again so a stored value is not shown.
     *
     * @var list<string>
     */
    private const HIDDEN_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'secret',
        'authorization',
        'client_secret',
        'private_integration_token',
        'remember_token',
        'ssh_key',
        'passphrase',
    ];

    #[Url(except: '')]
    public string $action = '';

    public function mount(): void
    {
        $this->guard();
    }

    public function render(): View
    {
        $this->guard();
        $filter = $this->actionFilter();

        return view('livewire.staff.activity-log', [
            'rows' => $filter['invalid'] ? [] : $this->rows($filter['action']),
            'filtering' => $filter['action'] !== null,
            'truncated' => $filter['invalid'] ? false : $this->matched($filter['action']) > self::LIMIT,
            'limit' => self::LIMIT,
            'invalid' => $filter['invalid'],
        ]);
    }

    private function guard(): void
    {
        $this->authorize('viewAny', AuditLog::class);
        abort_if(app(StaffLocationAccess::class)->isRestricted($this->currentUser()), 403);
    }

    /**
     * @return array{action: ?string, invalid: bool}
     */
    private function actionFilter(): array
    {
        $action = trim($this->action);

        if ($action === '') {
            return ['action' => null, 'invalid' => false];
        }

        if (mb_strlen($action) > 255) {
            $this->addError('action', 'Use 255 characters or fewer.');

            return ['action' => null, 'invalid' => true];
        }

        return ['action' => $action, 'invalid' => false];
    }

    private function matched(?string $action): int
    {
        return AuditLog::query()
            ->when($action !== null, fn ($query) => $query->where('action', $action))
            ->count();
    }

    /**
     * @return list<array{id: string, when: string, actor: string, label: string, detail: string, ip: ?string, bookingUrl: ?string, subject: ?string}>
     */
    private function rows(?string $action): array
    {
        $logs = AuditLog::query()
            ->with('actor:id,name')
            ->when($action !== null, fn ($query) => $query->where('action', $action))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $bookingIds = $logs
            ->filter(fn (AuditLog $log): bool => $log->subject_type === Booking::class && is_string($log->subject_id))
            ->pluck('subject_id')
            ->all();
        $open = $bookingIds === []
            ? []
            : Booking::query()->whereIn('id', $bookingIds)->pluck('id')->all();
        $zone = $this->defaultTimezone();
        $rows = [];

        foreach ($logs as $log) {
            $bookingUrl = is_string($log->subject_id) && in_array($log->subject_id, $open, true)
                ? '/staff/bookings/'.$log->subject_id
                : null;

            $rows[] = [
                'id' => $log->id,
                'when' => Display::dateTimeWithZone($log->created_at, $zone),
                'actor' => $log->actor instanceof User && $log->actor->name !== ''
                    ? $log->actor->name
                    : 'ReserveWorx',
                'label' => $this->label($log->action),
                'detail' => $this->detail($log->properties),
                'ip' => is_string($log->ip_address) && $log->ip_address !== '' ? $log->ip_address : null,
                'bookingUrl' => $bookingUrl,
                'subject' => $bookingUrl === null && is_string($log->subject_id)
                    ? strtoupper(substr($log->subject_id, -8))
                    : null,
            ];
        }

        return $rows;
    }

    private function label(string $action): string
    {
        $words = str_replace(['.', '_'], ' ', $action);

        return $words === '' ? 'Activity' : ucfirst($words);
    }

    /**
     * @param  array<mixed>  $properties
     */
    private function detail(array $properties): string
    {
        $parts = [];
        $this->collect($properties, $parts);
        $text = implode(' · ', $parts);

        if (mb_strlen($text) > 240) {
            return mb_substr($text, 0, 237).'…';
        }

        return $text;
    }

    /**
     * @param  array<mixed>  $properties
     * @param  list<string>  $parts
     */
    private function collect(array $properties, array &$parts): void
    {
        foreach ($properties as $key => $value) {
            if (count($parts) >= 6) {
                return;
            }

            if (is_string($key) && $this->hiddenKey($key)) {
                continue;
            }

            if (is_array($value)) {
                $this->collect($value, $parts);

                continue;
            }

            if (! is_scalar($value) || $value === '') {
                continue;
            }

            $shown = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;

            if (mb_strlen($shown) > 80) {
                $shown = mb_substr($shown, 0, 77).'…';
            }

            $name = is_string($key) ? str_replace('_', ' ', $key) : 'detail';
            $parts[] = $name.': '.$shown;
        }
    }

    private function hiddenKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::HIDDEN_KEYS as $needle) {
            if ($lower === $needle || str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
