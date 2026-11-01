<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Gateway\GatewayPayment;
use RuntimeException;

/** The provider reported a payment we cannot apply as-is. Nothing was credited. */
final class SettlementFailed extends RuntimeException
{
    public static function unknownDeposit(GatewayPayment $payment): self
    {
        return new self("No deposit {$payment->depositReference} at {$payment->gateway}.");
    }

    public static function mismatch(GatewayPayment $payment, Deposit $deposit): self
    {
        return new self(
            "Payment {$payment->transactionId} for {$payment->amount->toDecimal()} {$payment->amount->currency->value} "
            ."does not match deposit {$deposit->reference} ({$deposit->money()->toDecimal()} {$deposit->currency->value})."
        );
    }

    public static function settledByOtherTransaction(GatewayPayment $payment, Deposit $deposit): self
    {
        return new self("Deposit {$deposit->reference} was already settled by {$deposit->provider_transaction_id}, not {$payment->transactionId}.");
    }
}
