<?php

namespace App\Observers;

use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Pool;
use App\Services\Search\StaffSearchIndex;

class StaffSearchObserver
{
    public function __construct(private readonly StaffSearchIndex $index) {}

    public function saved(Asset|Booking|Customer|Pool $model): void
    {
        match (true) {
            $model instanceof Customer => $this->index->syncCustomer($model),
            $model instanceof Booking => $this->index->syncBooking($model),
            $model instanceof Asset => $this->index->syncAsset($model),
            $model instanceof Pool => $this->index->syncPool($model),
        };
    }

    public function deleting(Asset|Booking|Customer|Pool $model): void
    {
        if ($model instanceof Customer) {
            $this->index->forgetCustomerOnBookings($model);
        }
    }

    public function deleted(Asset|Booking|Customer|Pool $model): void
    {
        $type = match (true) {
            $model instanceof Customer => StaffSearchIndex::CUSTOMER,
            $model instanceof Booking => StaffSearchIndex::BOOKING,
            $model instanceof Asset => StaffSearchIndex::ASSET,
            $model instanceof Pool => StaffSearchIndex::POOL,
        };

        $this->index->forget($type, $model->tenant_id, $model->id);
    }
}
