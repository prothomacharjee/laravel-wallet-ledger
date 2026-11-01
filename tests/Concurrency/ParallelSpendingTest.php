<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Transfer\Transfer;
use App\Domain\Withdrawal\Withdrawal;
use Illuminate\Process\Pool;
use Illuminate\Process\ProcessPoolResults;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

/**
 * Real concurrency: 50 separate PHP processes, each with its own MySQL
 * connection, race to spend from one wallet. Data must be committed for the
 * children to see it, so this suite does not use RefreshDatabase.
 */
class ParallelSpendingTest extends TestCase
{
    use CreatesWallets;

    private const PROCESSES = 50;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh');
    }

    protected function tearDown(): void
    {
        // Leave an empty schema behind for any suite that runs after this one.
        Artisan::call('migrate:fresh');

        parent::tearDown();
    }

    public function test_fifty_parallel_transfers_never_overdraw_the_wallet(): void
    {
        $sender = $this->walletFor(balance: '100.00');
        $receiver = $this->walletFor();

        $results = $this->runInParallel([
            PHP_BINARY, base_path('artisan'), 'wallet:transfer', (string) $sender->id, (string) $receiver->id, '10.00',
        ]);

        $this->assertTenSucceededAndFortyWereRejected($results);
        $this->assertSame('0.00', $sender->refresh()->balance()->toDecimal());
        $this->assertSame('100.00', $receiver->refresh()->balance()->toDecimal());
        $this->assertSame('0.00', $sender->ledgerAccount->balance()->toDecimal());
        $this->assertSame(10, Transfer::query()->count());
        $this->assertSame([], $this->app->make(LedgerVerifier::class)->problems());
    }

    public function test_fifty_parallel_withdrawals_never_overdraw_the_wallet(): void
    {
        $wallet = $this->walletFor(balance: '100.00');

        $results = $this->runInParallel([PHP_BINARY, base_path('artisan'), 'wallet:withdraw', (string) $wallet->id, '10.00']);

        $this->assertTenSucceededAndFortyWereRejected($results);
        $this->assertSame('0.00', $wallet->refresh()->balance()->toDecimal());
        $this->assertSame('0.00', $wallet->ledgerAccount->balance()->toDecimal());
        $this->assertSame(10, Withdrawal::query()->count());
        $this->assertSame(10, DB::connection('gateway')->table('fake_gateway_withdrawals')->count());
        $this->assertSame([], $this->app->make(LedgerVerifier::class)->problems());
    }

    /** @param list<string> $command */
    private function runInParallel(array $command): ProcessPoolResults
    {
        return Process::pool(function (Pool $pool) use ($command): void {
            for ($i = 0; $i < self::PROCESSES; $i++) {
                $pool->timeout(300)->command($command);
            }
        })->start()->wait();
    }

    private function assertTenSucceededAndFortyWereRejected(ProcessPoolResults $results): void
    {
        $exitCodes = array_count_values(array_map(fn ($result) => (int) $result->exitCode(), $results->collect()->all()));
        $crashes = $results->collect()->reject(fn ($result) => in_array($result->exitCode(), [0, 2], true));

        $this->assertCount(0, $crashes, 'A process crashed: '.$crashes->map(fn ($r) => $r->errorOutput().$r->output())->implode("\n"));
        $this->assertSame(10, $exitCodes[0] ?? 0, 'Exactly 10 should succeed.');
        $this->assertSame(40, $exitCodes[2] ?? 0, 'Exactly 40 should be rejected cleanly.');

        foreach ($results->collect()->filter(fn ($r) => $r->exitCode() === 2) as $rejected) {
            $this->assertStringContainsString('insufficient funds', $rejected->output());
        }
    }
}
