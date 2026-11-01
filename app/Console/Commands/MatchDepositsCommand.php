<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Deposit\DepositMatcher;
use Illuminate\Console\Command;

final class MatchDepositsCommand extends Command
{
    protected $signature = 'deposits:match';

    protected $description = 'Settle deposits the gateways report as paid but whose webhook never arrived';

    public function handle(DepositMatcher $matcher): int
    {
        $counts = $matcher->run();

        $this->info("Checked {$counts['checked']} payment(s): {$counts['settled']} settled now, {$counts['failed']} failed.");

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
