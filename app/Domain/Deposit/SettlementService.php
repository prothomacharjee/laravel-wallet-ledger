<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Gateway\GatewayPayment;
use App\Domain\Ledger\LedgerAccount;
use App\Domain\Ledger\LedgerWriter;
use App\Domain\Wallet\WalletService;
use Illuminate\Database\ConnectionInterface;

/**
 * The one path that credits a deposit. Webhooks and the matcher both call
 * it, as often as they like: a payment is applied at most once.
 */
final readonly class SettlementService
{
    private const ATTEMPTS = 3;

    public function __construct(
        private ConnectionInterface $db,
        private WalletService $wallets,
        private LedgerWriter $ledger,
    ) {}

    /** @return bool true if this call credited the wallet, false if it was already settled */
    public function settle(GatewayPayment $payment): bool
    {
        return $this->db->transaction(function () use ($payment): bool {
            // Lock the deposit first, then the wallet. Two deliveries of the same
            // payment queue here; the second sees "settled" and stops.
            $deposit = Deposit::query()
                ->where('gateway', $payment->gateway)
                ->where('reference', $payment->depositReference)
                ->lockForUpdate()
                ->first() ?? throw SettlementFailed::unknownDeposit($payment);

            if ($deposit->status === DepositStatus::Settled) {
                if ($deposit->provider_transaction_id !== $payment->transactionId) {
                    throw SettlementFailed::settledByOtherTransaction($payment, $deposit);
                }

                return false;
            }

            if (! $deposit->money()->equals($payment->amount)) {
                throw SettlementFailed::mismatch($payment, $deposit);
            }

            $wallet = $this->wallets->lock($deposit->wallet_id);
            $this->wallets->credit($wallet, $deposit->money());

            // Unique key on provider_transaction_id: one provider payment can
            // never settle two different deposits.
            $deposit->forceFill([
                'status' => DepositStatus::Settled,
                'provider_transaction_id' => $payment->transactionId,
                'settled_at' => now(),
            ])->save();

            $clearing = LedgerAccount::forCode("gateway:{$payment->gateway}:{$deposit->currency->value}", $deposit->currency);
            $this->ledger->post('deposit', (string) $deposit->id, $clearing, $wallet->ledgerAccount, $deposit->money());

            return true;
        }, self::ATTEMPTS);
    }
}
