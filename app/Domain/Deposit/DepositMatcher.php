<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Gateway\GatewayRegistry;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Webhooks get lost. Every 30 seconds this asks each gateway for recent
 * completed payments and settles any we have not applied, through the same
 * idempotent SettlementService the webhook uses.
 */
final readonly class DepositMatcher
{
    public const LOOKBACK_MINUTES = 60;

    public function __construct(
        private GatewayRegistry $gateways,
        private SettlementService $settlement,
        private LoggerInterface $log,
    ) {}

    /** @return array{checked: int, settled: int, failed: int} */
    public function run(): array
    {
        $since = Carbon::now()->subMinutes(self::LOOKBACK_MINUTES);
        $counts = ['checked' => 0, 'settled' => 0, 'failed' => 0];

        foreach ($this->gateways->all() as $gateway) {
            foreach ($gateway->paymentsSince($since) as $payment) {
                $counts['checked']++;
                try {
                    if ($this->settlement->settle($payment)) {
                        $counts['settled']++;
                    }
                } catch (Throwable $e) {
                    $counts['failed']++;
                    $this->log->warning('Deposit matcher could not settle payment.', [
                        'gateway' => $payment->gateway,
                        'transaction_id' => $payment->transactionId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $counts;
    }
}
