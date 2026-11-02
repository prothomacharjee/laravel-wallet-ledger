<?php

declare(strict_types=1);

namespace App\Domain\Commission;

use App\Domain\Ledger\LedgerAccount;
use App\Domain\Ledger\LedgerWriter;
use App\Domain\Wallet\WalletService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DetectsConcurrencyErrors;
use Throwable;

/**
 * Pays accrued commission exactly once. One transaction per batch, one
 * savepoint per row: a row's wallet credits, ledger pairs and its
 * "processed" mark commit or roll back together, and a failing row never
 * takes the rest of the batch with it.
 */
final readonly class CommissionPayer
{
    use DetectsConcurrencyErrors;

    public const BATCH_SIZE = 500;

    /** A row that keeps failing is left for a person to look at instead of retried forever. */
    public const MAX_ATTEMPTS = 3;

    private const BATCH_ATTEMPTS = 3;

    public function __construct(
        private ConnectionInterface $db,
        private WalletService $wallets,
        private LedgerWriter $ledger,
        private CommissionService $commissions,
    ) {}

    /** @return array{paid: int, failed: int} */
    public function payBatch(): array
    {
        return $this->db->transaction(function (): array {
            // SKIP LOCKED: two payers running at once take different rows instead of waiting.
            $rows = CommissionAccrual::query()
                ->whereNull('processed_at')
                ->where('attempts', '<', self::MAX_ATTEMPTS)
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->lock('for update skip locked')
                ->with('partner.parent')
                ->get();

            $counts = ['paid' => 0, 'failed' => 0];

            foreach ($rows as $row) {
                try {
                    $this->db->transaction(fn () => $this->pay($row));
                    $counts['paid']++;
                } catch (Throwable $e) {
                    // A deadlock aborts the whole transaction, not just the savepoint: retry the batch.
                    if ($this->causedByConcurrencyError($e)) {
                        throw $e;
                    }

                    $row->forceFill(['attempts' => $row->attempts + 1, 'last_error' => $e->getMessage()])->save();
                    $counts['failed']++;
                }
            }

            return $counts;
        }, self::BATCH_ATTEMPTS);
    }

    private function pay(CommissionAccrual $row): void
    {
        $expense = LedgerAccount::forCode("commission-expense:{$row->currency->value}", $row->currency);

        foreach ($this->commissions->split($row->partner, $row->fee()) as [$partner, $amount]) {
            $wallet = $this->wallets->lock($this->wallets->open($partner->user, $row->currency)->id);
            $this->wallets->credit($wallet, $amount);

            $type = $partner->id === $row->partner_id ? 'commission' : 'commission-override';
            $this->ledger->post($type, (string) $row->id, $expense, $wallet->ledgerAccount, $amount);
        }

        $row->forceFill(['processed_at' => now()])->save();
    }
}
