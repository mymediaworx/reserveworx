<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GhlMessageLog;
use App\Models\GhlSyncLog;
use App\Models\TenantSetting;
use App\Services\Integrations\IntegrationRecovery;
use App\Services\Integrations\IntegrationRetryRefused;
use App\Services\Integrations\OutboxDiagnostics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function __construct(
        private readonly OutboxDiagnostics $diagnostics,
        private readonly IntegrationRecovery $recovery,
    ) {}

    public function show(): View
    {
        $this->authorize('viewAny', TenantSetting::class);
        $setting = TenantSetting::query()->first();

        return view('admin.integrations', [
            'summary' => $this->diagnostics->summary(),
            'canRetry' => $setting instanceof TenantSetting && Gate::allows('update', $setting),
        ]);
    }

    public function retrySync(Request $request, GhlSyncLog $ghl_sync_log): RedirectResponse
    {
        $this->authorizeRetry();

        try {
            $this->recovery->retrySync($ghl_sync_log, $request);
        } catch (IntegrationRetryRefused $exception) {
            return redirect('/admin/integrations')->with('error', $exception->getMessage());
        }

        return redirect('/admin/integrations')->with('status', 'That send was queued again.');
    }

    public function retryMessage(Request $request, GhlMessageLog $ghl_message_log): RedirectResponse
    {
        $this->authorizeRetry();

        try {
            $this->recovery->retryMessage($ghl_message_log, $request);
        } catch (IntegrationRetryRefused $exception) {
            return redirect('/admin/integrations')->with('error', $exception->getMessage());
        }

        return redirect('/admin/integrations')->with('status', 'That send was queued again.');
    }

    private function authorizeRetry(): void
    {
        $setting = TenantSetting::query()->first();

        if (! $setting instanceof TenantSetting) {
            abort(403);
        }

        $this->authorize('update', $setting);
    }
}
