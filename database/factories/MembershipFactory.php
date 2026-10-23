<?php

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'role_id' => fn (): string => Role::query()->where('slug', 'owner')->firstOrFail()->id,
            'status' => MembershipStatus::Active,
        ];
    }

    public function role(string $slug): static
    {
        return $this->state(fn (): array => [
            'role_id' => Role::query()->where('slug', $slug)->firstOrFail()->id,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['status' => MembershipStatus::Revoked]);
    }
}
