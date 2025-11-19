<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

Route::middleware([
    'web',
    Middleware\InitializeTenancyByDomain::class,
    Middleware\PreventAccessFromUnwantedDomains::class,
    Middleware\ScopeSessions::class,
])->group(function () {
    Route::get('/', function () {
        return 'This is your multi-tenant application. The id of the current tenant is ' . tenant('id') . "\n";
    });

    // Payment routes
    Route::prefix('payment')->group(function () {
        Route::get('/success', [App\Http\Controllers\Tenant\PaymentController::class, 'success'])
            ->name('tenant.payment.success');
        Route::get('/cancel', [App\Http\Controllers\Tenant\PaymentController::class, 'cancel'])
            ->name('tenant.payment.cancel');
        Route::post('/webhook', [App\Http\Controllers\Tenant\PaymentController::class, 'webhook'])
            ->name('tenant.payment.webhook');
    });
});
