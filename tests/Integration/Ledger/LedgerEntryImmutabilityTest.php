<?php

declare(strict_types=1);

namespace Tests\Integration\Ledger;

use App\Domain\Ledger\LedgerAccount;
use App\Domain\Ledger\LedgerEntry;
use App\Domain\Ledger\LedgerEntryIsImmutable;
use App\Domain\Ledger\LedgerWriter;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerEntryImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private LedgerEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();

        $from = LedgerAccount::create(['code' => 'clearing:USD', 'currency' => Currency::USD]);
        $to = LedgerAccount::create(['code' => 'wallet:1', 'currency' => Currency::USD]);
        $this->app->make(LedgerWriter::class)->post('deposit', '1', $from, $to, Money::of('5', Currency::USD));

        $this->entry = LedgerEntry::query()->firstOrFail();
    }

    public function test_ledger_entries_cannot_be_updated_or_deleted(): void
    {
        $this->assertModelRefuses(function (): void {
            $this->entry->amount = 1;
            $this->entry->save();
        });
        $this->assertModelRefuses(fn () => $this->entry->delete());

        $this->assertSame(500, $this->entry->fresh()?->amount);
        $this->assertSame(2, LedgerEntry::query()->count());
    }

    public function test_database_rejects_raw_updates_and_deletes_that_bypass_the_model(): void
    {
        $this->assertDatabaseRefuses(fn () => DB::table('ledger_entries')->update(['amount' => 1]));
        $this->assertDatabaseRefuses(fn () => DB::table('ledger_entries')->delete());

        $this->assertSame(2, DB::table('ledger_entries')->where('amount', 500)->count());
    }

    private function assertModelRefuses(callable $change): void
    {
        try {
            $change();
            $this->fail('The model allowed a ledger entry to change.');
        } catch (LedgerEntryIsImmutable) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertDatabaseRefuses(callable $change): void
    {
        try {
            $change();
            $this->fail('The database allowed a ledger entry to change.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }
}
