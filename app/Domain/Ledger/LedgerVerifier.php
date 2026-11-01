<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use Illuminate\Database\ConnectionInterface;

final readonly class LedgerVerifier
{
    public function __construct(private ConnectionInterface $db) {}

    /** @return list<string> one line per problem; empty when the ledger is consistent */
    public function problems(): array
    {
        $unbalanced = $this->db->table('ledger_entries')
            ->select('reference_type', 'reference_id', 'currency')
            ->groupBy('reference_type', 'reference_id', 'currency')
            ->havingRaw("SUM(CASE direction WHEN 'credit' THEN amount ELSE -CAST(amount AS SIGNED) END) <> 0")
            ->orderBy('reference_type')
            ->orderBy('reference_id')
            ->get();

        $problems = [];
        foreach ($unbalanced as $row) {
            $problems[] = "Reference {$row->reference_type}:{$row->reference_id} {$row->currency} does not net to zero.";
        }

        return $problems;
    }
}
