<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CreateDepositController;
use App\Http\Controllers\Api\CreateTransferController;
use App\Http\Controllers\Api\CreateWithdrawalController;
use App\Http\Controllers\Api\ReceiveWebhookController;
use App\Http\Middleware\SubmitLock;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', SubmitLock::class])->group(function (): void {
    Route::post('/transfers', CreateTransferController::class);
    Route::post('/deposits', CreateDepositController::class);
    Route::post('/withdrawals', CreateWithdrawalController::class);
});

Route::post('/webhooks/{gateway}', ReceiveWebhookController::class)
    ->whereIn('gateway', ['fake-crypto', 'fake-fiat']);
