<?php

use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\AvailabilityScheduleController;
use App\Http\Controllers\Api\V1\BlackoutController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\CurrentTenantController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\Platform\DomainController;
use App\Http\Controllers\Api\V1\Platform\FeeSettingsController;
use App\Http\Controllers\Api\V1\Platform\TenantController as PlatformTenantController;
use App\Http\Controllers\Api\V1\PoolController;
use App\Http\Controllers\Api\V1\ResourceCategoryController;
use App\Http\Controllers\Api\V1\ServiceController;
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
            Route::get('/locations', [LocationController::class, 'index']);
            Route::post('/locations', [LocationController::class, 'store']);
            Route::get('/locations/{location}', [LocationController::class, 'show']);
            Route::patch('/locations/{location}', [LocationController::class, 'update']);
            Route::get('/resource-categories', [ResourceCategoryController::class, 'index']);
            Route::post('/resource-categories', [ResourceCategoryController::class, 'store']);
            Route::get('/assets', [AssetController::class, 'index']);
            Route::post('/assets', [AssetController::class, 'store']);
            Route::get('/assets/{asset}', [AssetController::class, 'show']);
            Route::patch('/assets/{asset}', [AssetController::class, 'update']);
            Route::get('/pools', [PoolController::class, 'index']);
            Route::post('/pools', [PoolController::class, 'store']);
            Route::get('/pools/{pool}', [PoolController::class, 'show']);
            Route::patch('/pools/{pool}', [PoolController::class, 'update']);
            Route::get('/schedules', [AvailabilityScheduleController::class, 'index']);
            Route::post('/schedules', [AvailabilityScheduleController::class, 'store']);
            Route::delete('/schedules/{availability_schedule}', [AvailabilityScheduleController::class, 'destroy']);
            Route::get('/blackouts', [BlackoutController::class, 'index']);
            Route::post('/blackouts', [BlackoutController::class, 'store']);
            Route::delete('/blackouts/{blackout}', [BlackoutController::class, 'destroy']);
            Route::get('/services', [ServiceController::class, 'index']);
            Route::post('/services', [ServiceController::class, 'store']);
            Route::get('/services/{service}', [ServiceController::class, 'show']);
            Route::post('/services/{service}/requirements', [ServiceController::class, 'storeRequirement']);
            Route::get('/availability', [AvailabilityController::class, 'show']);
            Route::get('/calendar', [BookingController::class, 'calendar']);
            Route::get('/bookings', [BookingController::class, 'index']);
            Route::post('/bookings', [BookingController::class, 'store']);
            Route::get('/bookings/{booking}', [BookingController::class, 'show']);
            Route::post('/bookings/{booking}/confirm', [BookingController::class, 'confirm']);
            Route::post('/bookings/{booking}/extend', [BookingController::class, 'extend']);
            Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
        });
    });
});
