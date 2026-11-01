<?php

declare(strict_types=1);

namespace Tests\Integration\Transfer;

use App\Domain\Ledger\Direction;
use App\Domain\Ledger\LedgerEntry;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Transfer\TransferNotAllowed;
use App\Domain\Transfer\TransferService;
use App\Domain\Wallet\BalanceCapExceeded;
use App\Domain\Wallet\InsufficientFunds;
use App\Domain\Wallet\Wallet;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class TransferServiceTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    private TransferService $transfers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transfers = $this->app->make(TransferService::class);
    }

    public function test_transfer_writes_balanced_ledger_pair(): void
    {
        $alice = $this->walletFor(balance: '100.00');
        $bob = $this->walletFor();

        $transfer = $this->transfers->transfer($alice->id, $bob->id, Money::of('30.00', Currency::USD));

        $entries = LedgerEntry::query()->where('reference_type', 'transfer')->where('reference_id', (string) $transfer->id)->get();
        $this->assertCount(2, $entries);
        $this->assertSame($alice->ledger_account_id, $entries->firstWhere('direction', Direction::Debit)?->ledger_account_id);
        $this->assertSame($bob->ledger_account_id, $entries->firstWhere('direction', Direction::Credit)?->ledger_account_id);
        $this->assertSame([3000, 3000], $entries->pluck('amount')->all());

        $this->assertSame('70.00', $alice->refresh()->balance()->toDecimal());
        $this->assertSame('30.00', $bob->refresh()->balance()->toDecimal());
        $this->assertSame([], $this->app->make(LedgerVerifier::class)->problems());
    }

    public function test_transfer_rejected_when_funds_are_insufficient_changes_nothing(): void
    {
        $alice = $this->walletFor(balance: '10.00');
        $bob = $this->walletFor();

        $this->assertRejectedWithoutSideEffects(
            InsufficientFunds::class,
            fn () => $this->transfers->transfer($alice->id, $bob->id, Money::of('10.01', Currency::USD)),
            [$alice, $bob],
        );
    }

    public function test_transfer_rejected_when_receiver_would_exceed_balance_cap(): void
    {
        config(['wallet.max_balance.USD' => '50.00']);
        $alice = $this->walletFor(balance: '40.00');
        $bob = $this->walletFor(balance: '40.00');

        $this->assertRejectedWithoutSideEffects(
            BalanceCapExceeded::class,
            fn () => $this->transfers->transfer($alice->id, $bob->id, Money::of('10.01', Currency::USD)),
            [$alice, $bob],
        );
    }

    public function test_transfer_between_different_currencies_is_rejected(): void
    {
        $usd = $this->walletFor(balance: '10.00');
        $btc = $this->walletFor(currency: Currency::BTC);

        $this->assertRejectedWithoutSideEffects(
            TransferNotAllowed::class,
            fn () => $this->transfers->transfer($usd->id, $btc->id, Money::of('1.00', Currency::USD)),
            [$usd, $btc],
        );
    }

    public function test_wallets_are_locked_lowest_id_first_whichever_way_money_moves(): void
    {
        $low = $this->walletFor(balance: '10.00');
        $high = $this->walletFor(balance: '10.00');

        $locked = [];
        DB::listen(function (QueryExecuted $query) use (&$locked): void {
            if (str_contains($query->sql, 'for update')) {
                $locked[] = $query->bindings[0];
            }
        });

        $this->transfers->transfer($high->id, $low->id, Money::of('1.00', Currency::USD));
        $this->transfers->transfer($low->id, $high->id, Money::of('1.00', Currency::USD));

        $this->assertSame([$low->id, $high->id, $low->id, $high->id], $locked);
    }

    /**
     * @param  class-string<\Throwable>  $exception
     * @param  list<Wallet>  $wallets
     */
    private function assertRejectedWithoutSideEffects(string $exception, callable $transfer, array $wallets): void
    {
        $balances = array_map(fn (Wallet $w) => $w->refresh()->balance, $wallets);
        $entries = LedgerEntry::query()->count();

        try {
            $transfer();
            $this->fail("Expected {$exception}.");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);
        }

        $this->assertSame($balances, array_map(fn (Wallet $w) => $w->refresh()->balance, $wallets));
        $this->assertSame($entries, LedgerEntry::query()->count());
        $this->assertSame(0, DB::table('transfers')->count());
    }
}
