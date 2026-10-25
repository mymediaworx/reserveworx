<?php

namespace App\View\Composers;

use App\Enums\Industry;
use App\Tenancy\TenantManager;
use Illuminate\View\View;

/**
 * Shares the current workspace's industry vocabulary with staff views.
 * Layout variables are not in scope inside a Livewire view, so this runs on both.
 */
final class IndustryComposer
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function compose(View $view): void
    {
        $industry = $this->tenants->get()?->industry;
        $view->with('industry', $industry instanceof Industry ? $industry : Industry::BoatRentals);
    }
}
