<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Deposit\Deposit;
use App\Domain\Gateway\GatewayRegistry;
use App\Domain\Gateway\SignatureVerifier;
use Illuminate\Console\Command;

/** Demo helper: plays the customer paying at the fake gateway and prints the signed webhook. */
final class SimulatePaymentCommand extends Command
{
    protected $signature = 'gateway:simulate-payment {reference : Deposit reference returned by POST /api/deposits}';

    protected $description = 'Mark a deposit as paid at its fake gateway and print the signed webhook to send';

    public function handle(GatewayRegistry $gateways): int
    {
        $deposit = Deposit::query()->where('reference', (string) $this->argument('reference'))->firstOrFail();
        $gateway = $gateways->get($deposit->gateway);

        $body = $gateway->webhookBody($gateway->simulatePayment($deposit->reference));

        $this->line('Paid at '.$gateway->name().'. Send the webhook (or wait up to 30s for the matcher):');
        $this->newLine();
        $this->line('curl -X POST '.url("/api/webhooks/{$gateway->name()}").' \\');
        $this->line('  -H "Content-Type: application/json" \\');
        $this->line('  -H "X-Signature: '.SignatureVerifier::sign($body, $gateway->webhookSecret()).'" \\');
        $this->line("  -d '{$body}'");

        return self::SUCCESS;
    }
}
