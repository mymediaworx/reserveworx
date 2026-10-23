<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\GuestBookingController;
use App\Http\Controllers\WidgetController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('tenant.resolve')->group(function (): void {
    Route::get('/admin/login', [SessionController::class, 'create'])->name('admin.login');
    Route::post('/admin/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('admin.login.store');

    Route::middleware(['auth:web,sanctum', 'tenant.member'])->group(function (): void {
        Route::get('/admin', [DashboardController::class, 'show'])->name('admin.dashboard');
        Route::get('/admin/integrations', [IntegrationController::class, 'show'])->name('admin.integrations');
        Route::post('/admin/logout', [SessionController::class, 'destroy'])->name('admin.logout');
    });
});

Route::middleware(['tenant.resolve', 'throttle:widget'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class])
    ->prefix('book/{token}')
    ->group(function (): void {
        Route::get('/', [WidgetController::class, 'services'])->name('widget.services');
        Route::get('/services/{service}', [WidgetController::class, 'create'])->name('widget.create');
        Route::post('/services/{service}', [WidgetController::class, 'store'])->name('widget.store');
        Route::get('/checkout/{booking}', [WidgetController::class, 'checkout'])->name('widget.checkout')->middleware('signed');
        Route::post('/checkout/{booking}', [WidgetController::class, 'pay'])->name('widget.pay')->middleware('signed');
        Route::get('/confirmation/{booking}', [WidgetController::class, 'confirmation'])->name('widget.confirmation')->middleware('signed');
        Route::get('/lookup', [GuestBookingController::class, 'lookupForm'])->name('widget.lookup');
        Route::post('/lookup', [GuestBookingController::class, 'lookup'])->name('widget.lookup.store');
        Route::get('/manage/{booking}', [GuestBookingController::class, 'manage'])->name('widget.manage')->middleware('signed');
        Route::post('/manage/{booking}/cancel', [GuestBookingController::class, 'cancel'])->name('widget.guest-cancel')->middleware('signed');
        Route::post('/manage/{booking}/reschedule', [GuestBookingController::class, 'reschedule'])->name('widget.guest-reschedule')->middleware('signed');
    });
