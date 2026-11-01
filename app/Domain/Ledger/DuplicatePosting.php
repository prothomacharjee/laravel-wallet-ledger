<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use RuntimeException;
use Throwable;

final class DuplicatePosting extends RuntimeException
{
    public static function for(string $referenceType, string $referenceId, Throwable $previous): self
    {
        return new self("Reference {$referenceType}:{$referenceId} is already posted.", 0, $previous);
    }
}
