<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CurrentTenantController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\Platform\DomainController;
use App\Http\Controllers\Api\V1\Platform\FeeSettingsController;
use App\Http\Controllers\Api\V1\Platform\TenantController as PlatformTenantController;
use App\Http\Controllers\Api\V1\TenantSettingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::middleware('platform.admin')->prefix('platform')->group(function (): void {
            Route::get('/tenants', [PlatformTenantController::class, 'index']);
            Route::post('/tenants', [PlatformTenantController::class, 'store']);
            Route::get('/tenants/{tenant}', [PlatformTenantController::class, 'show']);
            Route::patch('/tenants/{tenant}', [PlatformTenantController::class, 'update']);
            Route::post('/tenants/{tenant}/domains', [DomainController::class, 'store']);
            Route::post('/tenants/{tenant}/domains/{domain}/verify', [DomainController::class, 'verify']);
            Route::patch('/tenants/{tenant}/fees', [FeeSettingsController::class, 'update']);
        });
    });

    Route::middleware('tenant.resolve')->group(function (): void {
        Route::middleware(['auth:sanctum', 'tenant.member'])->group(function (): void {
            Route::get('/tenant', [CurrentTenantController::class, 'show']);
            Route::get('/settings', [TenantSettingController::class, 'show']);
            Route::patch('/settings', [TenantSettingController::class, 'update']);
            Route::get('/members', [MembershipController::class, 'index']);
            Route::post('/members', [MembershipController::class, 'store']);
            Route::get('/members/{membership}', [MembershipController::class, 'show']);
            Route::patch('/members/{membership}', [MembershipController::class, 'update']);
            Route::get('/audit-logs', [AuditLogController::class, 'index']);
        });
    });
});
