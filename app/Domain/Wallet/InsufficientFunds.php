<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Money\Money;
use App\Domain\OperationRejected;

final class InsufficientFunds extends OperationRejected
{
    public static function in(Wallet $wallet, Money $requested): self
    {
        return new self("Wallet {$wallet->id} has insufficient funds for {$requested->toDecimal()} {$requested->currency->value}.");
    }
}
