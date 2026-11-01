<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use DateTimeInterface;

interface PaymentGateway
{
    public function name(): string;

    public function supports(Currency $currency): bool;

    /** Shared secret the provider signs webhooks with. */
    public function webhookSecret(): string;

    /** Registers a pending deposit with the provider under our reference. */
    public function createDeposit(string $reference, Money $amount): void;

    /**
     * Completed payments the provider reports since the given time. Used by
     * the matcher to catch deposits whose webhook never arrived.
     *
     * @return list<GatewayPayment>
     */
    public function paymentsSince(DateTimeInterface $since): array;
}
