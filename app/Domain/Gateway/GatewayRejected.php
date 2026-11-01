<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Domain\OperationRejected;

final class GatewayRejected extends OperationRejected
{
    public static function withdrawal(string $gateway, string $reason): self
    {
        return new self("{$gateway} rejected the withdrawal: {$reason}");
    }
}
