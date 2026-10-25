<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides rows outside the signed-in member's locations. An unrestricted
 * membership, a console command, and a request with no user add no filter.
 */
class StaffLocationScope implements Scope
{
    public function __construct(
        private readonly string $column = 'location_id',
        private readonly bool $includeUnassigned = false,
    ) {}

    public function apply(Builder $builder, Model $model): void
    {
        $ids = app(StaffLocationAccess::class)->restrictedLocationIds();

        if ($ids === null) {
            return;
        }

        $column = $model->qualifyColumn($this->column);

        if ($ids === []) {
            if ($this->includeUnassigned) {
                $builder->whereNull($column);

                return;
            }

            $builder->whereRaw('0 = 1');

            return;
        }

        if ($this->includeUnassigned) {
            $builder->where(function (Builder $query) use ($column, $ids): void {
                $query->whereNull($column)->orWhereIn($column, $ids);
            });

            return;
        }

        $builder->whereIn($column, $ids);
    }
}
