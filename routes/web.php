<?php

use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Api\V1\Platform\TenantExportController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\GuestBookingController;
use App\Http\Controllers\Web\FeedbackController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\PlatformAuthController;
use App\Http\Controllers\Web\PortfolioController;
use App\Http\Controllers\Web\SignupController;
use App\Http\Controllers\Web\StaffAuthController;
use App\Http\Controllers\Web\StaffBusinessController;
use App\Http\Controllers\Web\StaffCheckInController;
use App\Http\Controllers\Web\StaffImportTemplateController;
use App\Http\Controllers\Web\StaffIncidentPhotoController;
use App\Http\Controllers\Web\StaffInviteController;
use App\Http\Controllers\Web\StaffManifestController;
use App\Http\Controllers\Web\UiAssetController;
use App\Http\Controllers\Web\WaitlistLeaveController;
use App\Http\Controllers\Web\WaiverSigningController;
use App\Http\Controllers\WidgetController;
use App\Livewire\Platform\TenantDetail;
use App\Livewire\Platform\Tenants;
use App\Livewire\Staff\Bookings;
use App\Livewire\Staff\BookingShow;
use App\Livewire\Staff\Branding;
use App\Livewire\Staff\Calendar;
use App\Livewire\Staff\CheckIn;
use App\Livewire\Staff\Closures;
use App\Livewire\Staff\Dashboard;
use App\Livewire\Staff\Feedback;
use App\Livewire\Staff\Import;
use App\Livewire\Staff\Incidents;
use App\Livewire\Staff\LocationTax;
use App\Livewire\Staff\Maintenance;
use App\Livewire\Staff\Manifest;
use App\Livewire\Staff\Menu;
use App\Livewire\Staff\NewBooking;
use App\Livewire\Staff\Returns;
use App\Livewire\Staff\Schedule;
use App\Livewire\Staff\Settings;
use App\Livewire\Staff\Setup\Locations;
use App\Livewire\Staff\Setup\PromoCodes;
use App\Livewire\Staff\Setup\Resources;
use App\Livewire\Staff\Setup\ServiceDetail;
use App\Livewire\Staff\Setup\Services;
use App\Livewire\Staff\Share;
use App\Livewire\Staff\Team;
use App\Livewire\Staff\Waitlist;
use App\Livewire\Staff\Waivers;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', HomeController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class]);
Route::get('/ui/app.css', [UiAssetController::class, 'stylesheet']);
Route::get('/embed.js', [UiAssetController::class, 'embed'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class]);

/*
| Public signup, on the platform host only. A tenant domain has no signup page.
*/
Route::middleware('platform.host')->group(function (): void {
    Route::get('/signup', [SignupController::class, 'create'])->name('signup.create');
    Route::post('/signup', [SignupController::class, 'store'])->middleware('throttle:signup');
    Route::get('/signup/availability', [SignupController::class, 'availability'])->middleware('throttle:signup-availability');
    Route::get('/signup/check-email', [SignupController::class, 'checkEmail'])->name('signup.check-email');
    Route::post('/signup/resend', [SignupController::class, 'resend'])->middleware('throttle:signup-resend');
    Route::get('/signup/verify/{user}/{tenant}/{hash}', [SignupController::class, 'verify'])
        ->whereUlid('user')
        ->whereUlid('tenant')
        ->where('hash', '[a-f0-9]{40}')
        ->middleware(['signed', 'throttle:signup-verify'])
        ->name('signup.verify');
});

/*
| Platform administration, on the platform host only.
*/
Route::middleware('platform.host')->prefix('platform')->group(function (): void {
    Route::get('/login', [PlatformAuthController::class, 'show'])->name('platform.login');
    Route::post('/login', [PlatformAuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/forgot-password', [PlatformAuthController::class, 'requestForm'])->name('platform.password.request');
    Route::post('/forgot-password', [PlatformAuthController::class, 'sendReset'])->middleware('throttle:password-reset');
    Route::get('/reset-password/{token}', [PlatformAuthController::class, 'resetForm'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('platform.password.reset');
    Route::post('/reset-password', [PlatformAuthController::class, 'resetPassword'])->middleware('throttle:password-reset');
    Route::post('/logout', [PlatformAuthController::class, 'logout'])->middleware('auth');

    Route::middleware(['auth', 'platform.admin'])->group(function (): void {
        Route::get('/', Tenants::class);
        Route::get('/tenants/{tenant}', TenantDetail::class);
        Route::get('/tenants/{tenant}/exports/{export}', [TenantExportController::class, 'download'])->name('platform.exports.download');
    });
});

/*
| Staff area on a tenant's verified domain (Blade + Livewire). ResolveTenant sets
| the workspace from the host; tenant.member requires an active membership there.
*/
Route::middleware('tenant.resolve')->prefix('staff')->group(function (): void {
    Route::get('/login', [StaffAuthController::class, 'show'])->name('staff.login');
    Route::post('/login', [StaffAuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/forgot-password', [StaffAuthController::class, 'requestForm'])->name('staff.password.request');
    Route::post('/forgot-password', [StaffAuthController::class, 'sendReset'])->middleware('throttle:password-reset');
    Route::get('/reset-password/{token}', [StaffAuthController::class, 'resetForm'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('staff.password.reset');
    Route::post('/reset-password', [StaffAuthController::class, 'resetPassword'])->middleware('throttle:password-reset');
    Route::post('/logout', [StaffAuthController::class, 'logout'])->middleware('auth');
    Route::get('/enter/{token}', [StaffBusinessController::class, 'enter'])
        ->where('token', '[a-f0-9]{64}')
        ->middleware('throttle:10,1');
    Route::get('/invites/{membership}', [StaffInviteController::class, 'show'])
        ->whereUlid('membership')
        ->middleware(['signed', 'throttle:invite-accept'])
        ->name('staff.invite.show');
    Route::post('/invites/{membership}', [StaffInviteController::class, 'accept'])
        ->whereUlid('membership')
        ->middleware(['signed', 'throttle:invite-accept'])
        ->name('staff.invite.accept');

    Route::middleware(['auth', 'tenant.member'])->group(function (): void {
        Route::get('/', Dashboard::class)->name('staff.home');
        Route::get('/calendar', Calendar::class);
        Route::get('/schedule', Schedule::class);
        Route::get('/bookings', Bookings::class);
        Route::get('/bookings/new', NewBooking::class);
        Route::get('/bookings/{booking}', BookingShow::class);
        Route::get('/setup/locations', Locations::class);
        Route::get('/locations/{location}/tax', LocationTax::class)->whereUlid('location');
        Route::get('/closures', Closures::class);
        Route::get('/waivers', Waivers::class)->name('staff.waivers');
        Route::get('/setup/resources', Resources::class);
        Route::get('/setup/services', Services::class);
        Route::get('/setup/services/{service}', ServiceDetail::class);
        Route::get('/promo-codes', PromoCodes::class);
        Route::get('/import/templates/{type}', StaffImportTemplateController::class)
            ->where('type', 'customers|inventory');
        Route::get('/import', Import::class);
        Route::get('/settings', Settings::class);
        Route::get('/team', Team::class)->name('staff.team');
        Route::get('/feedback', Feedback::class);
        Route::get('/waitlist', Waitlist::class);
        Route::get('/manifest', Manifest::class)->name('staff.manifest');
        Route::get('/manifest.csv', [StaffManifestController::class, 'export'])->name('staff.manifest.export');
        Route::get('/check-in', CheckIn::class)->name('staff.check-in');
        Route::get('/returns', Returns::class)->name('staff.returns');
        Route::get('/incidents', Incidents::class)->name('staff.incidents');
        Route::get('/incidents/{incident}/photos/{photo}', [StaffIncidentPhotoController::class, 'show'])
            ->whereUlid('incident')
            ->whereUlid('photo')
            ->name('staff.incidents.photo');
        Route::get('/maintenance', Maintenance::class)->name('staff.maintenance');
        Route::get('/check-in/{booking}', [StaffCheckInController::class, 'show'])
            ->whereUlid('booking')
            ->middleware('signed')
            ->name('staff.check-in.arrive');
        Route::post('/check-in/{booking}', [StaffCheckInController::class, 'override'])
            ->whereUlid('booking')
            ->middleware('signed')
            ->name('staff.check-in.override');
        Route::get('/menu', Menu::class);
        Route::get('/branding', Branding::class);
        Route::get('/share', Share::class);
        Route::redirect('/booking-links', '/staff/share');
        Route::get('/portfolio', [PortfolioController::class, 'show']);
        Route::get('/portfolio.csv', [PortfolioController::class, 'export']);
        Route::get('/businesses/new', [StaffBusinessController::class, 'create']);
        Route::post('/businesses', [StaffBusinessController::class, 'store']);
        Route::post('/switch/{tenant}', [StaffBusinessController::class, 'switch'])->middleware('throttle:10,1');
    });
});

Route::middleware(['tenant.resolve', 'throttle:feedback'])->group(function (): void {
    Route::get('/waivers/{booking}', [WaiverSigningController::class, 'show'])
        ->whereUlid('booking')
        ->middleware('signed')
        ->name('waivers.sign');
    Route::post('/waivers/{booking}', [WaiverSigningController::class, 'store'])
        ->whereUlid('booking')
        ->middleware('signed')
        ->name('waivers.store');
    Route::get('/feedback/{booking}', [FeedbackController::class, 'show'])
        ->whereUlid('booking')
        ->middleware('signed')
        ->name('feedback.show');
    Route::post('/feedback/{booking}', [FeedbackController::class, 'store'])
        ->whereUlid('booking')
        ->middleware('signed')
        ->name('feedback.store');
    Route::match(['get', 'post'], '/feedback/{booking}/opt-out', [FeedbackController::class, 'optOut'])
        ->whereUlid('booking')
        ->middleware('signed')
        ->name('feedback.opt-out');
});

Route::middleware(['tenant.resolve', 'throttle:30,1'])->group(function (): void {
    Route::match(['get', 'post'], '/waitlist/{waitlistEntry}/leave', [WaitlistLeaveController::class, 'leave'])
        ->whereUlid('waitlistEntry')
        ->middleware('signed')
        ->name('waitlist.leave');
});

Route::middleware('tenant.resolve')->group(function (): void {
    Route::redirect('/admin', '/staff')->name('admin.dashboard');
    Route::get('/admin/login', [SessionController::class, 'create'])->name('admin.login');
    Route::post('/admin/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('admin.login.store');

    Route::middleware(['auth:web,sanctum', 'tenant.member'])->group(function (): void {
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
        Route::post('/services/{service}/waitlist', [WidgetController::class, 'joinWaitlist'])->name('widget.waitlist');
        Route::get('/checkout/{booking}', [WidgetController::class, 'checkout'])->name('widget.checkout')->middleware('signed');
        Route::post('/checkout/{booking}/promo', [WidgetController::class, 'applyPromo'])->name('widget.promo')->middleware('signed');
        Route::post('/checkout/{booking}', [WidgetController::class, 'pay'])->name('widget.pay')->middleware('signed');
        Route::post('/pickup/{booking}', [WidgetController::class, 'pickup'])->name('widget.pickup')->middleware('signed');
        Route::get('/confirmation/{booking}', [WidgetController::class, 'confirmation'])->name('widget.confirmation')->middleware('signed');
        Route::get('/lookup', [GuestBookingController::class, 'lookupForm'])->name('widget.lookup');
        Route::post('/lookup', [GuestBookingController::class, 'lookup'])->name('widget.lookup.store');
        Route::get('/manage/{booking}', [GuestBookingController::class, 'manage'])->name('widget.manage')->middleware('signed');
        Route::post('/manage/{booking}/cancel', [GuestBookingController::class, 'cancel'])->name('widget.guest-cancel')->middleware('signed');
        Route::post('/manage/{booking}/reschedule', [GuestBookingController::class, 'reschedule'])->name('widget.guest-reschedule')->middleware('signed');
        Route::post('/manage/{booking}/extend', [GuestBookingController::class, 'extend'])->name('widget.guest-extend')->middleware('signed');
    });
