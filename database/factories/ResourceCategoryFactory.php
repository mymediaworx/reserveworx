<?php

namespace Database\Factories;

use App\Models\ResourceCategory;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ResourceCategory>
 */
class ResourceCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'tenant_id' => Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
        ];
    }
}
