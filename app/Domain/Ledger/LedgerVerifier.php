<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use Illuminate\Database\ConnectionInterface;

final readonly class LedgerVerifier
{
    private const NET = "SUM(CASE direction WHEN 'credit' THEN amount ELSE -CAST(amount AS SIGNED) END)";

    public function __construct(private ConnectionInterface $db) {}

    /** @return list<string> one line per problem; empty when the ledger is consistent */
    public function problems(): array
    {
        return [...$this->unbalancedReferences(), ...$this->walletsOutOfStep()];
    }

    /** @return list<string> */
    private function unbalancedReferences(): array
    {
        $rows = $this->db->table('ledger_entries')
            ->select('reference_type', 'reference_id', 'currency')
            ->groupBy('reference_type', 'reference_id', 'currency')
            ->havingRaw(self::NET.' <> 0')
            ->orderBy('reference_type')
            ->orderBy('reference_id')
            ->get();

        return array_values($rows->map(fn (object $row): string => "Reference {$row->reference_type}:{$row->reference_id} {$row->currency} does not net to zero.")
            ->all());
    }

    /** @return list<string> */
    private function walletsOutOfStep(): array
    {
        $ledgerBalances = $this->db->table('ledger_entries')
            ->select('ledger_account_id')
            ->selectRaw(self::NET.' AS net')
            ->groupBy('ledger_account_id');

        $rows = $this->db->table('wallets')
            ->leftJoinSub($ledgerBalances, 'ledger', 'ledger.ledger_account_id', '=', 'wallets.ledger_account_id')
            ->select('wallets.id', 'wallets.balance')
            ->selectRaw('COALESCE(ledger.net, 0) AS net')
            ->whereRaw('wallets.balance <> COALESCE(ledger.net, 0)')
            ->orderBy('wallets.id')
            ->get();

        return array_values($rows->map(fn (object $row): string => "Wallet {$row->id} balance {$row->balance} does not match its ledger balance {$row->net} (minor units).")
            ->all());
    }
}
