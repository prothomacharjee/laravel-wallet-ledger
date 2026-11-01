<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Money\Currency;
use App\Domain\OperationRejected;

final class DepositNotAllowed extends OperationRejected
{
    public static function pendingExists(string $gateway): self
    {
        return new self("You already have a pending deposit with {$gateway}. Complete or wait for it first.");
    }

    public static function unsupportedCurrency(string $gateway, Currency $currency): self
    {
        return new self("{$gateway} does not accept {$currency->value}.");
    }
}
