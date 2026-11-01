<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

use App\Domain\Ledger\LedgerWriter;
use App\Domain\Money\Money;
use App\Domain\Wallet\Wallet;
use App\Domain\Wallet\WalletService;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final readonly class TransferService
{
    /** Attempts per transfer when MySQL reports a deadlock (1213) or lock wait timeout. */
    public const ATTEMPTS = 3;

    public function __construct(
        private ConnectionInterface $db,
        private WalletService $wallets,
        private LedgerWriter $ledger,
    ) {}

    public function transfer(int $fromWalletId, int $toWalletId, Money $amount): Transfer
    {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A transfer amount must be positive.');
        }

        if ($fromWalletId === $toWalletId) {
            throw TransferNotAllowed::toSameWallet();
        }

        return $this->db->transaction(function () use ($fromWalletId, $toWalletId, $amount): Transfer {
            [$from, $to] = $this->lockInIdOrder($fromWalletId, $toWalletId);

            // Every check below reads rows we hold locks on, so no other
            // transaction can change them between the check and the write.
            if ($from->currency !== $amount->currency || $to->currency !== $amount->currency) {
                throw TransferNotAllowed::currencyMismatch();
            }

            $this->wallets->debit($from, $amount);
            $this->wallets->credit($to, $amount);

            $transfer = new Transfer;
            $transfer->forceFill([
                'from_wallet_id' => $from->id,
                'to_wallet_id' => $to->id,
                'amount' => $amount->amount,
                'currency' => $amount->currency,
            ])->save();

            $this->ledger->post('transfer', (string) $transfer->id, $from->ledgerAccount, $to->ledgerAccount, $amount);

            return $transfer;
        }, self::ATTEMPTS);
    }

    /**
     * Two transfers A->B and B->A that each lock their sender first would
     * deadlock. Locking the lower ID first gives every transaction the same
     * order, so they queue instead.
     *
     * @return array{Wallet, Wallet} [from, to]
     */
    private function lockInIdOrder(int $fromWalletId, int $toWalletId): array
    {
        $locked = [];
        foreach ([min($fromWalletId, $toWalletId), max($fromWalletId, $toWalletId)] as $id) {
            $locked[$id] = $this->wallets->lock($id);
        }

        return [$locked[$fromWalletId], $locked[$toWalletId]];
    }
}
