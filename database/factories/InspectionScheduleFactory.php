<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\InspectionSchedule;
use App\Models\Pool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InspectionSchedule>
 */
class InspectionScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'pool_id' => null,
            'name' => 'Safety check',
            'interval_days' => 30,
            'interval_uses' => null,
            'last_done_at' => null,
            'next_due_at' => now()->addDays(30),
        ];
    }

    public function forPool(Pool $pool): static
    {
        return $this->state(fn (): array => [
            'asset_id' => null,
            'pool_id' => $pool->id,
            'tenant_id' => $pool->tenant_id,
        ]);
    }

    public function everyUses(int $uses): static
    {
        return $this->state(fn (): array => [
            'interval_days' => null,
            'interval_uses' => $uses,
            'next_due_at' => null,
        ]);
    }

    public function configure(): static
    {
        return $this->afterMaking(function (InspectionSchedule $schedule): void {
            if ($schedule->asset_id !== null) {
                $asset = Asset::withoutGlobalScopes()->find($schedule->asset_id);

                if ($asset !== null) {
                    $schedule->tenant_id = $asset->tenant_id;
                }

                return;
            }

            if ($schedule->pool_id !== null) {
                $pool = Pool::withoutGlobalScopes()->find($schedule->pool_id);

                if ($pool !== null) {
                    $schedule->tenant_id = $pool->tenant_id;
                }
            }
        });
    }
}
