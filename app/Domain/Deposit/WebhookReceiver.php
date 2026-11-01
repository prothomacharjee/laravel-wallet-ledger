<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Gateway\GatewayPayment;
use App\Domain\Gateway\GatewayRegistry;
use App\Domain\Gateway\SignatureVerifier;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Illuminate\Database\ConnectionInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Records every webhook call, verifies it, and settles it. It never throws:
 * the provider always gets 202, and anything that went wrong is in
 * webhook_calls. A deposit left unsettled is picked up by DepositMatcher.
 */
final readonly class WebhookReceiver
{
    public function __construct(
        private ConnectionInterface $db,
        private GatewayRegistry $gateways,
        private SettlementService $settlement,
    ) {}

    public function receive(string $gatewayName, string $body, ?string $signature): void
    {
        $gateway = $this->gateways->get($gatewayName);
        $valid = SignatureVerifier::verify($body, $signature, $gateway->webhookSecret());

        $callId = $this->db->table('webhook_calls')->insertGetId([
            'gateway' => $gatewayName,
            'signature_valid' => $valid,
            'status' => $valid ? 'received' : 'rejected',
            'payload' => $body,
            'error' => $valid ? null : 'Invalid signature.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $valid) {
            return;
        }

        try {
            $applied = $this->settlement->settle($this->parse($gatewayName, $body));
            $this->finish($callId, $applied ? 'processed' : 'duplicate', null);
        } catch (Throwable $e) {
            $this->finish($callId, 'failed', $e->getMessage());
        }
    }

    private function parse(string $gateway, string $body): GatewayPayment
    {
        $data = json_decode($body, true);
        if (! is_array($data) || ($data['event'] ?? null) !== 'payment.completed') {
            throw new UnexpectedValueException('Unsupported webhook payload.');
        }

        foreach (['transaction_id', 'reference', 'amount', 'currency'] as $field) {
            if (! is_string($data[$field] ?? null)) {
                throw new UnexpectedValueException("Webhook field \"{$field}\" is missing.");
            }
        }

        return new GatewayPayment(
            $gateway,
            $data['transaction_id'],
            $data['reference'],
            Money::of($data['amount'], Currency::from($data['currency'])),
        );
    }

    private function finish(int $callId, string $status, ?string $error): void
    {
        $this->db->table('webhook_calls')->where('id', $callId)
            ->update(['status' => $status, 'error' => $error, 'updated_at' => now()]);
    }
}
