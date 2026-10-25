<?php

namespace Database\Factories;

use App\Enums\Industry;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();
        $slug = Str::slug($name);
        $slug = $slug === '' ? 'tenant' : substr($slug, 0, 50);

        return [
            'name' => $name,
            'slug' => $slug.'-'.Str::lower(Str::random(4)),
            'status' => TenantStatus::Active,
            'industry' => Industry::BoatRentals,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            TenantSetting::factory()->create([
                'tenant_id' => $tenant->id,
            ]);
        });
    }

    public function onboarding(): static
    {
        return $this->state(fn (): array => ['status' => TenantStatus::Onboarding]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => TenantStatus::Suspended]);
    }

    public function terminated(): static
    {
        return $this->state(fn (): array => ['status' => TenantStatus::Terminated]);
    }
}
