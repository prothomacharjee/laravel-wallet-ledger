<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CreateTransferController;
use App\Http\Middleware\SubmitLock;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', SubmitLock::class])->group(function (): void {
    Route::post('/transfers', CreateTransferController::class);
});
