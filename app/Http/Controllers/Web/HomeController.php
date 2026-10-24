<?php

namespace App\Http\Controllers\Web;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WidgetController;
use App\Models\Domain;
use App\Services\Widget\WidgetTokens;
use App\Support\Hostname;
use App\Support\Hosts;
use App\Tenancy\TenancyException;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * The platform host shows the product page. A verified tenant domain shows
     * that workspace's public booking page. Staff sign-in stays at /staff/login.
     */
    public function __invoke(Request $request, TenantManager $tenants, WidgetTokens $tokens, WidgetController $widget): View
    {
        if (Hosts::isPlatform($request)) {
            return view('welcome');
        }

        $domain = Domain::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('hostname', Hostname::normalize($request->getHost()))
            ->whereNotNull('verified_at')
            ->first();

        if (! $domain instanceof Domain) {
            throw TenancyException::notFound();
        }

        $tenant = $domain->tenant;

        if ($tenant === null || in_array($tenant->status, [TenantStatus::Suspended, TenantStatus::Terminated], true)) {
            throw TenancyException::unavailable();
        }

        $tenants->set($tenant);
        $request->attributes->set('tenant', $tenant);

        try {
            return $widget->services($request, $tokens->hostedPlain($tenant));
        } finally {
            $tenants->forget();
        }
    }
}
