<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ledger\LedgerVerifier;
use Illuminate\Console\Command;

final class VerifyLedger extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Check that every ledger reference nets to zero and every wallet balance matches its ledger account';

    public function handle(LedgerVerifier $verifier): int
    {
        $problems = $verifier->problems();

        if ($problems === []) {
            $this->info('Ledger OK: every reference nets to zero and every wallet matches its ledger balance.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }
        $this->error(count($problems).' problem(s) found.');

        return self::FAILURE;
    }
}
