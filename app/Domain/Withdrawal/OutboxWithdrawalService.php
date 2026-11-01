<?php

declare(strict_types=1);

namespace App\Domain\Withdrawal;

use App\Domain\Gateway\GatewayRegistry;
use App\Domain\Gateway\GatewayRejected;
use App\Domain\Ledger\LedgerAccount;
use App\Domain\Ledger\LedgerWriter;
use App\Domain\Money\Money;
use App\Domain\Wallet\Wallet;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The outbox version of WithdrawalService. The debit and an outbox message
 * commit together; the gateway is called afterwards by a queue worker, never
 * while a wallet row is locked. ADR 0005 compares the two.
 */
final readonly class OutboxWithdrawalService
{
    private const ATTEMPTS = 3;

    public function __construct(
        private ConnectionInterface $db,
        private WalletService $wallets,
        private LedgerWriter $ledger,
        private GatewayRegistry $gateways,
        private WithdrawalLimits $limits,
        private Dispatcher $bus,
        private LoggerInterface $log,
    ) {}

    /** Debits the wallet now and queues the payout. Returns a pending withdrawal. */
    public function request(User $user, string $gatewayName, Money $amount, string $destination): Withdrawal
    {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A withdrawal amount must be positive.');
        }

        if (! $this->gateways->get($gatewayName)->supports($amount->currency)) {
            throw WithdrawalNotAllowed::unsupportedCurrency($gatewayName, $amount->currency);
        }

        $walletId = Wallet::query()->where('user_id', $user->id)->where('currency', $amount->currency)->value('id')
            ?? throw WithdrawalNotAllowed::noWallet($amount->currency);

        [$withdrawal, $outboxId] = $this->db->transaction(function () use ($user, $gatewayName, $amount, $destination, $walletId): array {
            $wallet = $this->wallets->lock((int) $walletId);
            $this->limits->check($wallet, $amount);
            $this->wallets->debit($wallet, $amount);

            $withdrawal = new Withdrawal;
            $withdrawal->forceFill([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'gateway' => $gatewayName,
                'reference' => (string) Str::uuid(),
                'amount' => $amount->amount,
                'currency' => $amount->currency,
                'destination' => $destination,
                'status' => WithdrawalStatus::Pending,
            ])->save();

            $this->ledger->post('withdrawal', (string) $withdrawal->id, $wallet->ledgerAccount, $this->clearing($withdrawal), $amount);

            $outboxId = $this->db->table('withdrawal_outbox')->insertGetId([
                'withdrawal_id' => $withdrawal->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [$withdrawal, (int) $outboxId];
        }, self::ATTEMPTS);

        try {
            $this->bus->dispatch(new SendWithdrawal($outboxId));
        } catch (Throwable $e) {
            // Never fail the request here: a 500 would invite the client to retry a debit that already committed.
            // The debit and its outbox message are committed; the relay will send it.
            $this->log->warning('Could not queue withdrawal; the outbox relay will retry.', ['outbox_id' => $outboxId, 'error' => $e->getMessage()]);
        }

        return $withdrawal;
    }

    /**
     * Sends one outbox message to the gateway. Safe to run any number of
     * times, in parallel: the gateway dedupes by our reference, and the
     * result is applied under a row lock only while the withdrawal is pending.
     */
    public function send(int $outboxId): void
    {
        $message = $this->db->table('withdrawal_outbox')->where('id', $outboxId)->first();
        if ($message === null || $message->processed_at !== null) {
            return;
        }

        $withdrawal = Withdrawal::query()->whereKey($message->withdrawal_id)->firstOrFail();
        if ($withdrawal->status !== WithdrawalStatus::Pending) {
            $this->markProcessed($outboxId);

            return;
        }

        try {
            $transactionId = $this->gateways->get($withdrawal->gateway)
                ->withdraw($withdrawal->reference, $withdrawal->money(), $withdrawal->destination);
        } catch (GatewayRejected $e) {
            $this->refund($withdrawal, $outboxId, $e->getMessage());

            return;
        } catch (Throwable $e) {
            // Outage or timeout: the money stays debited and the payout is retried.
            $this->db->table('withdrawal_outbox')->where('id', $outboxId)
                ->increment('attempts', 1, ['last_error' => $e->getMessage(), 'updated_at' => now()]);

            throw $e;
        }

        $this->db->transaction(function () use ($withdrawal, $outboxId, $transactionId): void {
            $locked = Withdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            if ($locked->status === WithdrawalStatus::Pending) {
                $locked->forceFill(['status' => WithdrawalStatus::Completed, 'provider_transaction_id' => $transactionId])->save();
            }
            $this->markProcessed($outboxId);
        }, self::ATTEMPTS);
    }

    private function refund(Withdrawal $withdrawal, int $outboxId, string $reason): void
    {
        $this->db->transaction(function () use ($withdrawal, $outboxId, $reason): void {
            // Wallet first, then withdrawal: the same order request() takes them in.
            $wallet = $this->wallets->lock($withdrawal->wallet_id);
            $locked = Withdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if ($locked->status === WithdrawalStatus::Pending) {
                $this->wallets->refund($wallet, $locked->money());
                $locked->forceFill(['status' => WithdrawalStatus::Failed])->save();
                $this->ledger->post('withdrawal-reversal', (string) $locked->id, $this->clearing($locked), $wallet->ledgerAccount, $locked->money());
            }

            $this->markProcessed($outboxId, $reason);
        }, self::ATTEMPTS);
    }

    private function markProcessed(int $outboxId, ?string $error = null): void
    {
        $this->db->table('withdrawal_outbox')->where('id', $outboxId)->update([
            'processed_at' => now(),
            'last_error' => $error,
            'updated_at' => now(),
        ]);
    }

    private function clearing(Withdrawal $withdrawal): LedgerAccount
    {
        return LedgerAccount::forCode("gateway:{$withdrawal->gateway}:{$withdrawal->currency->value}", $withdrawal->currency);
    }
}
