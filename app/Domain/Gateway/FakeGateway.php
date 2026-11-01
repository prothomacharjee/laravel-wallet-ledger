<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Stands in for a real provider. Its "remote" records live in the
 * fake_gateway_payments table so separate processes (web, scheduler,
 * artisan) all see the same provider state.
 */
abstract class FakeGateway implements PaymentGateway
{
    public function __construct(
        protected readonly ConnectionInterface $db,
        protected readonly Config $config,
    ) {}

    /** @return list<Currency> */
    abstract protected function currencies(): array;

    public function supports(Currency $currency): bool
    {
        return in_array($currency, $this->currencies(), true);
    }

    public function webhookSecret(): string
    {
        return (string) $this->config->get("services.{$this->name()}.webhook_secret", '');
    }

    public function createDeposit(string $reference, Money $amount): void
    {
        $this->db->table('fake_gateway_payments')->insert([
            'gateway' => $this->name(),
            'deposit_reference' => $reference,
            'amount' => $amount->amount,
            'currency' => $amount->currency->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function paymentsSince(DateTimeInterface $since): array
    {
        $rows = $this->db->table('fake_gateway_payments')
            ->where('gateway', $this->name())
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $since)
            ->orderBy('id')
            ->get();

        return array_values($rows->map(fn (object $row): GatewayPayment => new GatewayPayment(
            $this->name(),
            $row->transaction_id,
            $row->deposit_reference,
            Money::ofMinor((int) $row->amount, Currency::from($row->currency)),
        ))->all());
    }

    /** Simulates the customer paying. Returns what the provider would then report. */
    public function simulatePayment(string $depositReference): GatewayPayment
    {
        $transactionId = $this->name().'_'.Str::lower((string) Str::ulid());

        $this->db->table('fake_gateway_payments')
            ->where('gateway', $this->name())
            ->where('deposit_reference', $depositReference)
            ->whereNull('paid_at')
            ->update(['transaction_id' => $transactionId, 'paid_at' => now(), 'updated_at' => now()]);

        $row = $this->db->table('fake_gateway_payments')->where('deposit_reference', $depositReference)->first();
        if ($row === null) {
            throw new \InvalidArgumentException("No payment for deposit {$depositReference} at {$this->name()}.");
        }

        return new GatewayPayment(
            $this->name(),
            $row->transaction_id,
            $depositReference,
            Money::ofMinor((int) $row->amount, Currency::from($row->currency)),
        );
    }

    /** The body the provider would POST to our webhook for this payment. */
    public function webhookBody(GatewayPayment $payment): string
    {
        return (string) json_encode([
            'event' => 'payment.completed',
            'transaction_id' => $payment->transactionId,
            'reference' => $payment->depositReference,
            'amount' => $payment->amount->toDecimal(),
            'currency' => $payment->amount->currency->value,
        ]);
    }
}
