<?php

namespace App\Models;

use App\Enums\TransactionalEmail;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TenantNotificationSetting extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'tenant_notification_settings';

    protected $fillable = [
        'tenant_id',
        'reply_to',
        'staff_alert_recipients',
        'transactional_emails',
    ];

    protected $casts = [
        'staff_alert_recipients' => 'array',
        'transactional_emails' => 'array',
    ];

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        $recipients = [];

        foreach ($this->staff_alert_recipients as $recipient) {
            if (is_string($recipient) && $recipient !== '') {
                $recipients[] = $recipient;
            }
        }

        return $recipients;
    }

    /**
     * Stored choices overlaid on the default (every known message on).
     * A missing kind stays on.
     *
     * @return array<string, bool>
     */
    public function preferences(): array
    {
        $merged = TransactionalEmail::defaults();

        foreach (TransactionalEmail::cases() as $case) {
            if (array_key_exists($case->value, $this->transactional_emails)) {
                $merged[$case->value] = filter_var($this->transactional_emails[$case->value], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $merged;
    }

    public function allows(string $kind): bool
    {
        $preferences = $this->preferences();

        return $preferences[$kind] ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditSnapshot(): array
    {
        return [
            'reply_to' => $this->reply_to,
            'staff_alert_recipients' => $this->recipients(),
            'transactional_emails' => $this->preferences(),
        ];
    }
}
