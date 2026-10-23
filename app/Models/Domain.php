<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'hostname', 'type', 'verified_at', 'is_primary'])]
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'is_primary' => 'boolean',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
