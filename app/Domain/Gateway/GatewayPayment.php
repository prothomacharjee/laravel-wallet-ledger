<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Domain\Money\Money;

/** A completed payment as the provider reports it, by webhook or by polling. */
final readonly class GatewayPayment
{
    public function __construct(
        public string $gateway,
        public string $transactionId,
        public string $depositReference,
        public Money $amount,
    ) {}
}
