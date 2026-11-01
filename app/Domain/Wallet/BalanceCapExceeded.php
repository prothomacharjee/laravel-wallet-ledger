<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Money\Money;
use App\Domain\OperationRejected;

final class BalanceCapExceeded extends OperationRejected
{
    public static function for(Wallet $wallet, Money $cap): self
    {
        return new self("Wallet {$wallet->id} would exceed its maximum balance of {$cap->toDecimal()} {$cap->currency->value}.");
    }
}
