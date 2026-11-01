<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use LogicException;

final class LedgerEntryIsImmutable extends LogicException
{
    public static function create(): self
    {
        return new self('Ledger entries are append-only. Post a reversing entry instead.');
    }
}
