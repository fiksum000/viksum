<?php
use Illuminate\Support\Facades\Route; use App\Http\Controllers\TripayWebhookController;
Route::post('/webhooks/tripay',TripayWebhookController::class)->name('tripay.webhook');
