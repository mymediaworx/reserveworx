<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\GuestBookingController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\StaffAuthController;
use App\Http\Controllers\Web\UiAssetController;
use App\Http\Controllers\WidgetController;
use App\Livewire\Staff\BookingLinks;
use App\Livewire\Staff\Bookings;
use App\Livewire\Staff\BookingShow;
use App\Livewire\Staff\Calendar;
use App\Livewire\Staff\Dashboard;
use App\Livewire\Staff\NewBooking;
use App\Livewire\Staff\Settings;
use App\Livewire\Staff\Setup\Locations;
use App\Livewire\Staff\Setup\Resources;
use App\Livewire\Staff\Setup\ServiceDetail;
use App\Livewire\Staff\Setup\Services;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', HomeController::class);
Route::get('/ui/app.css', [UiAssetController::class, 'stylesheet']);

/*
| Staff area on a tenant's verified domain (Blade + Livewire). ResolveTenant sets
| the workspace from the host; tenant.member requires an active membership there.
*/
Route::middleware('tenant.resolve')->prefix('staff')->group(function (): void {
    Route::get('/login', [StaffAuthController::class, 'show'])->name('staff.login');
    Route::post('/login', [StaffAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/logout', [StaffAuthController::class, 'logout'])->middleware('auth');

    Route::middleware(['auth', 'tenant.member'])->group(function (): void {
        Route::get('/', Dashboard::class)->name('staff.home');
        Route::get('/calendar', Calendar::class);
        Route::get('/bookings', Bookings::class);
        Route::get('/bookings/new', NewBooking::class);
        Route::get('/bookings/{booking}', BookingShow::class);
        Route::get('/setup/locations', Locations::class);
        Route::get('/setup/resources', Resources::class);
        Route::get('/setup/services', Services::class);
        Route::get('/setup/services/{service}', ServiceDetail::class);
        Route::get('/settings', Settings::class);
        Route::get('/booking-links', BookingLinks::class);
    });
});

Route::middleware('tenant.resolve')->group(function (): void {
    Route::get('/admin/login', [SessionController::class, 'create'])->name('admin.login');
    Route::post('/admin/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('admin.login.store');

    Route::middleware(['auth:web,sanctum', 'tenant.member'])->group(function (): void {
        Route::get('/admin', [DashboardController::class, 'show'])->name('admin.dashboard');
        Route::get('/admin/today.csv', [ReportController::class, 'export'])->name('admin.report.export');
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
