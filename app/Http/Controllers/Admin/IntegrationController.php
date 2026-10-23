<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantSetting;
use App\Services\Integrations\OutboxDiagnostics;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function __construct(private readonly OutboxDiagnostics $diagnostics) {}

    public function show(): View
    {
        $this->authorize('viewAny', TenantSetting::class);

        return view('admin.integrations', [
            'summary' => $this->diagnostics->summary(),
        ]);
    }
}
