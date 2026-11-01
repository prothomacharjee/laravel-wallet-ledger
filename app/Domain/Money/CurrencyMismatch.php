<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

final class CurrencyMismatch extends InvalidArgumentException
{
    public static function between(Currency $a, Currency $b): self
    {
        return new self("Cannot combine {$a->value} with {$b->value}.");
    }
}
