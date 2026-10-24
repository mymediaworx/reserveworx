<?php

namespace App\Models;

use App\Domain\Pricing\PriceRuleInput;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceRule extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'service_id', 'name', 'kind', 'amount_cents', 'window', 'duration_minutes', 'starts_on', 'ends_on',
        'local_start_minute', 'local_end_minute', 'weekdays',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'duration_minutes' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'local_start_minute' => 'integer',
        'local_end_minute' => 'integer',
        'weekdays' => 'array',
    ];

    /**
     * Rules for one service, in id order. An earlier rule wins a peak tie.
     *
     * @return list<PriceRuleInput>
     */
    public static function inputsFor(string $serviceId): array
    {
        $inputs = [];

        foreach (self::query()->where('service_id', $serviceId)->orderBy('id')->get() as $rule) {
            $inputs[] = $rule->toInput();
        }

        return $inputs;
    }

    public function toInput(): PriceRuleInput
    {
        return new PriceRuleInput(
            (string) $this->kind,
            (int) $this->amount_cents,
            (string) $this->window,
            $this->duration_minutes === null ? null : (int) $this->duration_minutes,
            $this->starts_on?->toDateString(),
            $this->ends_on?->toDateString(),
            $this->minute($this->local_start_minute),
            $this->minute($this->local_end_minute),
            $this->weekdayList(),
        );
    }

    private function minute(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return list<int>|null
     */
    private function weekdayList(): ?array
    {
        $stored = $this->weekdays;

        if (! is_array($stored) || $stored === []) {
            return null;
        }

        $days = [];

        foreach ($stored as $day) {
            if (is_int($day)) {
                $days[] = $day;
            } elseif (is_string($day) && preg_match('/^\d+$/', $day) === 1) {
                $days[] = (int) $day;
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days === [] ? null : $days;
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
