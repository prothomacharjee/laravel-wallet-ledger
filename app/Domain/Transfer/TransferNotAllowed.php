<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

use App\Domain\OperationRejected;

final class TransferNotAllowed extends OperationRejected
{
    public static function toSameWallet(): self
    {
        return new self('A wallet cannot transfer to itself.');
    }

    public static function currencyMismatch(): self
    {
        return new self('Both wallets and the amount must use the same currency.');
    }
}
