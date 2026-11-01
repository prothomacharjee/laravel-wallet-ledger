<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Withdrawal\WithdrawalService;
use App\Http\Requests\WithdrawalRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class CreateWithdrawalController
{
    public function __invoke(WithdrawalRequest $request, WithdrawalService $withdrawals): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $withdrawal = $withdrawals->withdraw(
            $user,
            $request->string('gateway')->value(),
            $request->money(),
            $request->string('destination')->value(),
        );

        return response()->json([
            'reference' => $withdrawal->reference,
            'gateway' => $withdrawal->gateway,
            'status' => $withdrawal->status->value,
            'amount' => $withdrawal->money()->toDecimal(),
            'currency' => $withdrawal->currency->value,
            'provider_transaction_id' => $withdrawal->provider_transaction_id,
        ], 201);
    }
}
