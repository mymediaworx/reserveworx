<?php

namespace Database\Factories;

use App\Models\SignupAcceptance;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SignupTerms;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignupAcceptance>
 */
class SignupAcceptanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'terms_version' => SignupTerms::VERSION,
            'accepted_at' => now(),
        ];
    }
}
