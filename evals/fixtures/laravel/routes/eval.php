<?php

use App\Http\Controllers\OrderSummaryController;
use App\Http\Controllers\PaymentLinkController;
use Illuminate\Support\Facades\Route;

Route::post('/payment-links', [PaymentLinkController::class, 'store']);
Route::post('/reports/summary/{currency}', OrderSummaryController::class);
