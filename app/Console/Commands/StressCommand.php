<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\OperationRejected;
use App\Domain\Transfer\TransferService;
use App\Domain\Wallet\Wallet;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Fires random concurrent transfers between the seeded USD wallets from many
 * OS processes, then checks that no money was created or lost.
 */
final class StressCommand extends Command
{
    protected $signature = 'wallet:stress
        {--workers=50 : Parallel processes}
        {--transfers=1000 : Total transfers across all workers}
        {--max-amount=60.00 : Largest random transfer, in USD}
        {--worker : Internal: run as one worker and print JSON counts}';

    protected $description = 'Run random concurrent transfers and prove the money total and the ledger hold';

    public function handle(ConnectionInterface $db, TransferService $transfers, LedgerVerifier $verifier): int
    {
        return $this->option('worker')
            ? $this->runWorker($transfers)
            : $this->runParent($db, $verifier);
    }

    private function runParent(ConnectionInterface $db, LedgerVerifier $verifier): int
    {
        $workers = max(1, (int) $this->option('workers'));
        $total = max(1, (int) $this->option('transfers'));

        if (Wallet::query()->where('currency', Currency::USD)->count() < 2) {
            $this->error('Need at least two USD wallets. Run: php artisan db:seed');

            return self::FAILURE;
        }

        $before = $this->totalMoney($db);
        $deadlocksBefore = $this->innodbMetric($db, 'lock_deadlocks');
        $timeoutsBefore = $this->innodbMetric($db, 'lock_timeouts');
        $started = microtime(true);

        $results = Process::pool(function (Pool $pool) use ($workers, $total): void {
            for ($i = 0; $i < $workers; $i++) {
                $share = intdiv($total, $workers) + ($i < $total % $workers ? 1 : 0);
                $pool->timeout(600)->command([
                    PHP_BINARY, base_path('artisan'), 'wallet:stress', '--worker',
                    "--transfers={$share}", '--max-amount='.$this->option('max-amount'),
                ]);
            }
        })->start()->wait();

        $counts = ['succeeded' => 0, 'rejected' => 0, 'errors' => 0];
        foreach ($results as $result) {
            /** @var array{succeeded?: int, rejected?: int, errors?: int}|null $worker */
            $worker = json_decode(trim($result->output()), true);
            if (! $result->successful() || ! is_array($worker)) {
                $counts['errors']++;
                $this->error(trim($result->errorOutput() ?: $result->output()));

                continue;
            }
            foreach ($counts as $key => $value) {
                $counts[$key] = $value + ($worker[$key] ?? 0);
            }
        }

        $after = $this->totalMoney($db);
        $problems = $verifier->problems();
        $moneyHeld = $before->equals($after);

        $this->table(['Metric', 'Value'], [
            ['Workers', $workers],
            ['Transfers attempted', $total],
            ['Succeeded', $counts['succeeded']],
            ['Rejected (business rule)', $counts['rejected']],
            ['Errors', $counts['errors']],
            ['Deadlocks (detected and retried)', $this->delta($this->innodbMetric($db, 'lock_deadlocks'), $deadlocksBefore)],
            ['Lock wait timeouts', $this->delta($this->innodbMetric($db, 'lock_timeouts'), $timeoutsBefore)],
            ['Total money before', $before->toDecimal().' USD'],
            ['Total money after', $after->toDecimal().' USD'],
            ['Money conserved', $moneyHeld ? 'yes' : 'NO'],
            ['ledger:verify', $problems === [] ? 'OK' : count($problems).' problem(s)'],
            ['Duration', round(microtime(true) - $started, 1).'s'],
        ]);

        return $moneyHeld && $problems === [] && $counts['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function runWorker(TransferService $transfers): int
    {
        $walletIds = Wallet::query()->where('currency', Currency::USD)->pluck('id')->all();
        $maxMinor = Money::of((string) $this->option('max-amount'), Currency::USD)->amount;
        $counts = ['succeeded' => 0, 'rejected' => 0, 'errors' => 0];

        for ($i = 0; $i < (int) $this->option('transfers'); $i++) {
            [$from, $to] = array_map(fn (int|string $key) => $walletIds[$key], (array) array_rand($walletIds, 2));
            if (random_int(0, 1) === 1) {
                [$from, $to] = [$to, $from];
            }

            try {
                $transfers->transfer($from, $to, Money::ofMinor(random_int(1, $maxMinor), Currency::USD));
                $counts['succeeded']++;
            } catch (OperationRejected) {
                $counts['rejected']++;
            } catch (Throwable $e) {
                $counts['errors']++;
                $this->getOutput()->getErrorStyle()->writeln($e->getMessage());
            }
        }

        $this->line((string) json_encode($counts));

        return self::SUCCESS;
    }

    private function totalMoney(ConnectionInterface $db): Money
    {
        return Money::ofMinor((int) $db->table('wallets')->where('currency', Currency::USD->value)->sum('balance'), Currency::USD);
    }

    private function innodbMetric(ConnectionInterface $db, string $name): ?int
    {
        $value = $db->table('information_schema.INNODB_METRICS')->where('NAME', $name)->value('COUNT');

        return $value === null ? null : (int) $value;
    }

    private function delta(?int $after, ?int $before): string
    {
        return $after === null || $before === null ? 'n/a' : (string) ($after - $before);
    }
}
