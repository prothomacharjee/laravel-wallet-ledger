<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Gateway\GatewayRegistry;

final class WithdrawalRequest extends DepositRequest
{
    /** @return array<string, mixed> */
    public function rules(GatewayRegistry $gateways): array
    {
        return parent::rules($gateways) + [
            'destination' => ['required', 'string', 'max:255'],
        ];
    }
}
