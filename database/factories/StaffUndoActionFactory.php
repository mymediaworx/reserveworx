<?php

namespace Database\Factories;

use App\Enums\StaffUndoKind;
use App\Models\StaffUndoAction;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffUndoAction>
 */
class StaffUndoActionFactory extends Factory
{
    protected $model = StaffUndoAction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'location_id' => null,
            'kind' => StaffUndoKind::ClosureDelete,
            'subject_type' => null,
            'subject_id' => null,
            'payload' => [],
            'message' => 'Removed.',
            'expires_at' => now()->addSeconds(10),
            'undone_at' => null,
            'committed_at' => null,
        ];
    }
}
