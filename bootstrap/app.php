<?php

use App\Http\Middleware\EnsureKnownHost;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureTenantMembership;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(EnsureKnownHost::class);
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ResolveTenant::class,
        );
        $middleware->alias([
            'tenant.resolve' => ResolveTenant::class,
            'tenant.member' => EnsureTenantMembership::class,
            'platform.admin' => EnsurePlatformAdmin::class,
        ]);
        $middleware->redirectGuestsTo(fn (): string => route('admin.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
