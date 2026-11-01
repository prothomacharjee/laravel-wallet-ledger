<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ledger\LedgerAccount;
use App\Domain\Ledger\LedgerWriter;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class LedgerVerifyCommandTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    private LedgerAccount $clearing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearing = LedgerAccount::create(['code' => 'clearing:USD', 'currency' => Currency::USD]);
        $wallet = LedgerAccount::create(['code' => 'wallet:1', 'currency' => Currency::USD]);
        $this->app->make(LedgerWriter::class)
            ->post('deposit', '1', $this->clearing, $wallet, Money::of('5', Currency::USD));
    }

    public function test_ledger_verify_passes_on_a_balanced_ledger(): void
    {
        $this->assertSame(0, Artisan::call('ledger:verify'));
        $this->assertStringContainsString('Ledger OK', Artisan::output());
    }

    public function test_ledger_verify_detects_an_unbalanced_reference(): void
    {
        DB::table('ledger_entries')->insert([
            'ledger_account_id' => $this->clearing->id,
            'reference_type' => 'deposit',
            'reference_id' => '99',
            'direction' => 'debit',
            'amount' => 100,
            'currency' => 'USD',
            'created_at' => now(),
        ]);

        $this->assertSame(1, Artisan::call('ledger:verify'));
        $this->assertStringContainsString('deposit:99 USD does not net to zero', Artisan::output());
    }

    public function test_ledger_verify_detects_a_tampered_balance(): void
    {
        $wallet = $this->walletFor(balance: '10.00');
        $this->assertSame(0, Artisan::call('ledger:verify'));

        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => 1_000_000]);

        $this->assertSame(1, Artisan::call('ledger:verify'));
        $this->assertStringContainsString(
            "Wallet {$wallet->id} balance 1000000 does not match its ledger balance 1000",
            Artisan::output(),
        );
    }
}
