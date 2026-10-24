<?php

namespace App\Http\Controllers\Web\Concerns;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;

trait RendersStaffPage
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function staffPage(string $title, string $view, array $data = []): View
    {
        return view('layouts.staff', [
            'title' => $title,
            'slot' => new HtmlString(view($view, $data)->render()),
        ]);
    }

    protected function staffTenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    protected function staffUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
