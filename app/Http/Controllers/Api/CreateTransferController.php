<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Transfer\TransferService;
use App\Http\Requests\TransferRequest;
use Illuminate\Http\JsonResponse;

final class CreateTransferController
{
    public function __invoke(TransferRequest $request, TransferService $transfers): JsonResponse
    {
        $transfer = $transfers->transfer(
            $request->integer('from_wallet_id'),
            $request->integer('to_wallet_id'),
            $request->money(),
        );

        return response()->json([
            'id' => $transfer->id,
            'from_wallet_id' => $transfer->from_wallet_id,
            'to_wallet_id' => $transfer->to_wallet_id,
            'amount' => $transfer->money()->toDecimal(),
            'currency' => $transfer->currency->value,
        ], 201);
    }
}
