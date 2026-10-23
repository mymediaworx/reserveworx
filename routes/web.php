<?php

use App\Http\Controllers\WidgetController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    return view('welcome');
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
    });
