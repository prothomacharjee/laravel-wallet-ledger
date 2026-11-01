<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Deposit\DepositService;
use App\Http\Requests\DepositRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class CreateDepositController
{
    public function __invoke(DepositRequest $request, DepositService $deposits): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $deposit = $deposits->create($user, $request->string('gateway')->value(), $request->money());

        return response()->json([
            'reference' => $deposit->reference,
            'gateway' => $deposit->gateway,
            'status' => $deposit->status->value,
            'amount' => $deposit->money()->toDecimal(),
            'currency' => $deposit->currency->value,
        ], 201);
    }
}
