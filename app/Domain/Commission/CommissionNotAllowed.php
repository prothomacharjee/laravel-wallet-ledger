<?php

declare(strict_types=1);

namespace App\Domain\Commission;

use App\Domain\OperationRejected;

final class CommissionNotAllowed extends OperationRejected
{
    public static function rateOutOfRange(int $rateBps): self
    {
        return new self("A commission rate must be between 1 and 10000 basis points, got {$rateBps}.");
    }

    public static function chainTooDeep(): self
    {
        return new self('A sub-partner can only sit under a top-level partner.');
    }

    public static function rateTooCloseToParent(int $rateBps, int $parentRateBps, int $minGapBps): self
    {
        return new self("A sub-partner rate ({$rateBps} bps) must be at least {$minGapBps} bps below its parent's ({$parentRateBps} bps).");
    }

    public static function feeNotPositive(): self
    {
        return new self('A commission fee must be positive.');
    }
}
