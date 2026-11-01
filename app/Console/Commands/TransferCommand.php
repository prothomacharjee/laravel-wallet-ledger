<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Money\Money;
use App\Domain\OperationRejected;
use App\Domain\Transfer\TransferService;
use App\Domain\Wallet\Wallet;
use Illuminate\Console\Command;

/**
 * One transfer per process. The concurrency test starts many of these at once,
 * each with its own PHP process and database connection, to race them for
 * real. Exit codes: 0 succeeded, 2 rejected by a business rule, 1 crashed.
 */
final class TransferCommand extends Command
{
    protected $signature = 'wallet:transfer {from : Sender wallet ID} {to : Receiver wallet ID} {amount : Decimal amount in the sender\'s currency}';

    protected $description = 'Transfer money between two wallets';

    public function handle(TransferService $transfers): int
    {
        $from = Wallet::query()->findOrFail((int) $this->argument('from'));
        $amount = Money::of((string) $this->argument('amount'), $from->currency);

        try {
            $transfer = $transfers->transfer($from->id, (int) $this->argument('to'), $amount);
        } catch (OperationRejected $e) {
            $this->line((string) json_encode(['status' => 'rejected', 'reason' => $e->getMessage()]));

            return self::INVALID;
        }

        $this->line((string) json_encode(['status' => 'succeeded', 'transfer_id' => $transfer->id]));

        return self::SUCCESS;
    }
}
