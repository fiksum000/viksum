<?php

use App\Http\Controllers\Api\V1\BillingApiController;
use App\Http\Controllers\TripayWebhookController;
use App\Http\Controllers\FonnteWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/tripay', TripayWebhookController::class)->name('tripay.webhook');
Route::post('/webhooks/fonnte/{token}', FonnteWebhookController::class)->middleware('throttle:120,1')->name('fonnte.webhook');

Route::prefix('v1')->middleware(['throttle:60,1', 'api.token:read'])->name('api.v1.')->group(function (): void {
    Route::get('/customers', [BillingApiController::class, 'customers'])->name('customers.index');
    Route::get('/customers/{customer}', [BillingApiController::class, 'customer'])->whereNumber('customer')->name('customers.show');
    Route::get('/invoices', [BillingApiController::class, 'invoices'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [BillingApiController::class, 'invoice'])->whereNumber('invoice')->name('invoices.show');
    Route::get('/routers', [BillingApiController::class, 'routers'])->name('routers.index');
});
