<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use RuntimeException;

/** The provider could not be reached or timed out. Unlike a rejection, the call may be retried. */
final class GatewayUnavailable extends RuntimeException
{
    public static function for(string $gateway): self
    {
        return new self("{$gateway} is unavailable.");
    }
}
