<?php

declare(strict_types=1);

namespace App\Domain\Withdrawal;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\OperationRejected;

final class WithdrawalNotAllowed extends OperationRejected
{
    public static function noWallet(Currency $currency): self
    {
        return new self("You have no {$currency->value} wallet.");
    }

    public static function limitReached(string $period, Money $limit, Money $used): self
    {
        return new self(ucfirst($period)." withdrawal limit is {$limit->toDecimal()} {$limit->currency->value}; "
            ."{$used->toDecimal()} already withdrawn this ".($period === 'daily' ? 'day' : 'month').'.');
    }

    public static function unsupportedCurrency(string $gateway, Currency $currency): self
    {
        return new self("{$gateway} does not pay out {$currency->value}.");
    }
}
