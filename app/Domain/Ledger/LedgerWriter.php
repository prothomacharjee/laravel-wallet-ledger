<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Money\CurrencyMismatch;
use App\Domain\Money\Money;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use LogicException;

final readonly class LedgerWriter
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * Writes one balanced pair: debit $from, credit $to, same amount.
     *
     * Must run inside the caller's transaction so the entries commit or roll
     * back together with the balance change they describe. The unique key on
     * (reference, account, direction) makes a repeated posting fail instead of
     * applying twice.
     *
     * @throws DuplicatePosting when this reference is already posted
     */
    public function post(string $referenceType, string $referenceId, LedgerAccount $from, LedgerAccount $to, Money $amount): void
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Ledger postings must run inside a database transaction.');
        }

        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A posting amount must be positive.');
        }

        if ($from->id === $to->id) {
            throw new InvalidArgumentException('An account cannot be posted against itself.');
        }

        foreach ([$from, $to] as $account) {
            if ($account->currency !== $amount->currency) {
                throw CurrencyMismatch::between($account->currency, $amount->currency);
            }
        }

        $row = [
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'amount' => $amount->amount,
            'currency' => $amount->currency->value,
            'created_at' => now(),
        ];

        try {
            // One statement, so both legs are written or neither is.
            $this->db->table('ledger_entries')->insert([
                [...$row, 'ledger_account_id' => $from->id, 'direction' => Direction::Debit->value],
                [...$row, 'ledger_account_id' => $to->id, 'direction' => Direction::Credit->value],
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw DuplicatePosting::for($referenceType, $referenceId, $e);
        }
    }
}
