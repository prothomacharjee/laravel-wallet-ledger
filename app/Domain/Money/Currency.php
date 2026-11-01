<?php

declare(strict_types=1);

namespace App\Domain\Money;

enum Currency: string
{
    case USD = 'USD';
    case BDT = 'BDT';
    case BTC = 'BTC';
    case USDT = 'USDT';

    /** Number of decimal places stored in minor units. */
    public function scale(): int
    {
        return match ($this) {
            self::USD, self::BDT => 2,
            self::USDT => 6,
            self::BTC => 8,
        };
    }
}
