<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Commission\CommissionPayer;
use Illuminate\Console\Command;

final class PayCommissionsCommand extends Command
{
    protected $signature = 'commissions:pay';

    protected $description = 'Pay accrued partner commission in batches, each row exactly once';

    public function handle(CommissionPayer $payer): int
    {
        $paid = 0;
        $failed = 0;

        do {
            $batch = $payer->payBatch();
            $paid += $batch['paid'];
            $failed += $batch['failed'];
        } while ($batch['paid'] + $batch['failed'] === CommissionPayer::BATCH_SIZE);

        $this->info("Paid {$paid} commission row(s), {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
