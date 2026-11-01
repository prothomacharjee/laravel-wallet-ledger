<?php

declare(strict_types=1);

namespace App\Domain\Withdrawal;

use App\Domain\Money\Currency;
use App\Domain\OperationRejected;

final class WithdrawalNotAllowed extends OperationRejected
{
    public static function noWallet(Currency $currency): self
    {
        return new self("You have no {$currency->value} wallet.");
    }

    public static function unsupportedCurrency(string $gateway, Currency $currency): self
    {
        return new self("{$gateway} does not pay out {$currency->value}.");
    }
}
