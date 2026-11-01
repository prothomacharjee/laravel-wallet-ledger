<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Money\Money;
use App\Domain\OperationRejected;
use App\Domain\Wallet\Wallet;
use App\Domain\Withdrawal\WithdrawalService;
use Illuminate\Console\Command;

/**
 * One withdrawal per process, for the concurrency test. Exit codes:
 * 0 succeeded, 2 rejected by a business rule, 1 crashed.
 */
final class WithdrawCommand extends Command
{
    protected $signature = 'wallet:withdraw {wallet : Wallet ID} {amount : Decimal amount in the wallet\'s currency}
        {--gateway=fake-fiat} {--destination=demo-account}';

    protected $description = 'Withdraw money from a wallet through a fake gateway';

    public function handle(WithdrawalService $withdrawals): int
    {
        $wallet = Wallet::query()->with('user')->findOrFail((int) $this->argument('wallet'));

        try {
            $withdrawal = $withdrawals->withdraw(
                $wallet->user,
                (string) $this->option('gateway'),
                Money::of((string) $this->argument('amount'), $wallet->currency),
                (string) $this->option('destination'),
            );
        } catch (OperationRejected $e) {
            $this->line((string) json_encode(['status' => 'rejected', 'reason' => $e->getMessage()]));

            return self::INVALID;
        }

        $this->line((string) json_encode(['status' => 'succeeded', 'reference' => $withdrawal->reference]));

        return self::SUCCESS;
    }
}
