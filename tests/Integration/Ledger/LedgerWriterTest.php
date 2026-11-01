<?php

declare(strict_types=1);

namespace Tests\Integration\Ledger;

use App\Domain\Ledger\Direction;
use App\Domain\Ledger\DuplicatePosting;
use App\Domain\Ledger\LedgerAccount;
use App\Domain\Ledger\LedgerEntry;
use App\Domain\Ledger\LedgerWriter;
use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatch;
use App\Domain\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class LedgerWriterTest extends TestCase
{
    use RefreshDatabase;

    private LedgerWriter $writer;

    private LedgerAccount $clearing;

    private LedgerAccount $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = $this->app->make(LedgerWriter::class);
        $this->clearing = LedgerAccount::create(['code' => 'clearing:USD', 'currency' => Currency::USD]);
        $this->wallet = LedgerAccount::create(['code' => 'wallet:1', 'currency' => Currency::USD]);
    }

    public function test_posting_writes_a_balanced_debit_credit_pair(): void
    {
        $this->writer->post('deposit', '42', $this->clearing, $this->wallet, Money::of('25.00', Currency::USD));

        $entries = LedgerEntry::query()->where('reference_id', '42')->get();

        $this->assertCount(2, $entries);
        $debit = $entries->firstWhere('direction', Direction::Debit);
        $credit = $entries->firstWhere('direction', Direction::Credit);
        $this->assertNotNull($debit);
        $this->assertNotNull($credit);
        $this->assertSame($this->clearing->id, $debit->ledger_account_id);
        $this->assertSame($this->wallet->id, $credit->ledger_account_id);
        $this->assertSame(2500, $debit->amount);
        $this->assertSame(2500, $credit->amount);

        $this->assertSame('25.00', $this->wallet->balance()->toDecimal());
        $this->assertSame('-25.00', $this->clearing->balance()->toDecimal());
    }

    public function test_same_reference_cannot_be_posted_twice(): void
    {
        $amount = Money::of('10.00', Currency::USD);
        $this->writer->post('deposit', '7', $this->clearing, $this->wallet, $amount);

        try {
            $this->writer->post('deposit', '7', $this->clearing, $this->wallet, $amount);
            $this->fail('A duplicate posting was accepted.');
        } catch (DuplicatePosting) {
            $this->assertSame(2, LedgerEntry::query()->count());
            $this->assertSame('10.00', $this->wallet->balance()->toDecimal());
        }
    }

    public function test_amount_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->writer->post('deposit', '1', $this->clearing, $this->wallet, Money::zero(Currency::USD));
    }

    public function test_an_account_cannot_be_posted_against_itself(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->writer->post('deposit', '1', $this->wallet, $this->wallet, Money::of('1', Currency::USD));
    }

    public function test_amount_currency_must_match_both_accounts(): void
    {
        $this->expectException(CurrencyMismatch::class);

        $this->writer->post('deposit', '1', $this->clearing, $this->wallet, Money::of('1', Currency::BDT));
    }
}
