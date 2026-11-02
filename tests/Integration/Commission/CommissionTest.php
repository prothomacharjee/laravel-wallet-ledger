<?php

declare(strict_types=1);

namespace Tests\Integration\Commission;

use App\Domain\Commission\CommissionAccrual;
use App\Domain\Commission\CommissionNotAllowed;
use App\Domain\Commission\CommissionPayer;
use App\Domain\Commission\CommissionService;
use App\Domain\Commission\Partner;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Wallet\Wallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class CommissionTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    private CommissionService $commissions;

    private CommissionPayer $payer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->commissions = $this->app->make(CommissionService::class);
        $this->payer = $this->app->make(CommissionPayer::class);
    }

    public function test_sub_partner_rate_must_be_five_points_below_parent(): void
    {
        $parent = $this->partner(2000);

        $this->assertSame(1500, $this->partner(1500, $parent)->rate_bps);

        $this->expectException(CommissionNotAllowed::class);
        $this->partner(1501, $parent);
    }

    public function test_chain_is_at_most_two_levels(): void
    {
        $child = $this->partner(1500, $this->partner(2000));

        $this->expectException(CommissionNotAllowed::class);
        $this->partner(500, $child);
    }

    public function test_chain_payout_never_exceeds_the_top_rate(): void
    {
        $parent = $this->partner(2000);
        $child = $this->partner(1500, $parent);

        // 15% of 0.99 = 0.1485 and 20% = 0.198: shares come from one rounded-down total.
        $this->commissions->accrue($child, Money::of('0.99', Currency::USD), 'fee-1');
        $this->commissions->accrue($child, Money::of('100.00', Currency::USD), 'fee-2');

        $this->assertSame(['paid' => 2, 'failed' => 0], $this->payer->payBatch());

        $this->assertSame('15.14', $this->balance($child));
        $this->assertSame('5.05', $this->balance($parent));
        $this->assertSame([], $this->app->make(LedgerVerifier::class)->problems());
    }

    public function test_commission_rows_are_paid_exactly_once_even_when_run_twice(): void
    {
        $partner = $this->partner(1000);
        $this->commissions->accrue($partner, Money::of('50.00', Currency::USD), 'fee-1');
        $this->commissions->accrue($partner, Money::of('50.00', Currency::USD), 'fee-1');

        $this->assertSame(['paid' => 1, 'failed' => 0], $this->payer->payBatch());
        $this->assertSame(['paid' => 0, 'failed' => 0], $this->payer->payBatch());

        $this->assertSame('5.00', $this->balance($partner));
        $this->assertSame(1, CommissionAccrual::query()->count());
        $this->assertNotNull(CommissionAccrual::query()->value('processed_at'));
    }

    public function test_a_failing_row_rolls_back_alone_and_the_rest_of_the_batch_is_paid(): void
    {
        $parent = $this->partner(2000);
        $child = $this->partner(1500, $parent);
        $other = $this->partner(1000);

        // The parent wallet is already at the cap, so its override fails after the child's share was credited.
        $this->walletFor($parent->user, balance: '50000.00');

        $this->commissions->accrue($child, Money::of('100.00', Currency::USD), 'fee-1');
        $this->commissions->accrue($other, Money::of('100.00', Currency::USD), 'fee-2');

        $this->assertSame(['paid' => 1, 'failed' => 1], $this->payer->payBatch());

        $this->assertSame('0.00', $this->balance($child), 'the child share rolled back with its row');
        $this->assertSame('10.00', $this->balance($other));

        $failed = CommissionAccrual::query()->where('source_reference', 'fee-1')->firstOrFail();
        $this->assertNull($failed->processed_at);
        $this->assertSame(1, $failed->attempts);
        $this->assertNotNull($failed->last_error);
        $this->assertSame([], $this->app->make(LedgerVerifier::class)->problems());
    }

    public function test_a_row_that_keeps_failing_stops_being_retried(): void
    {
        $partner = $this->partner(1000);
        $this->walletFor($partner->user, balance: '50000.00');
        $this->commissions->accrue($partner, Money::of('100.00', Currency::USD), 'fee-1');

        for ($i = 0; $i < CommissionPayer::MAX_ATTEMPTS; $i++) {
            $this->assertSame(['paid' => 0, 'failed' => 1], $this->payer->payBatch());
        }

        $this->assertSame(['paid' => 0, 'failed' => 0], $this->payer->payBatch());
    }

    public function test_commission_is_paid_in_batches_of_five_hundred(): void
    {
        $partner = $this->partner(1000);
        $rows = [];
        for ($i = 1; $i <= CommissionPayer::BATCH_SIZE + 1; $i++) {
            $rows[] = ['partner_id' => $partner->id, 'source_reference' => "fee-{$i}", 'fee_amount' => 100, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('commission_accruals')->insert($rows);

        $this->assertSame(['paid' => 500, 'failed' => 0], $this->payer->payBatch());
        $this->assertSame(['paid' => 1, 'failed' => 0], $this->payer->payBatch());
        $this->assertSame('50.10', $this->balance($partner));
    }

    public function test_pay_command_drains_all_batches(): void
    {
        $partner = $this->partner(1000);
        $this->commissions->accrue($partner, Money::of('20.00', Currency::USD), 'fee-1');

        $this->assertSame(0, Artisan::call('commissions:pay'));
        $this->assertStringContainsString('Paid 1 commission row(s), 0 failed.', Artisan::output());
        $this->assertSame('2.00', $this->balance($partner));
    }

    private function partner(int $rateBps, ?Partner $parent = null): Partner
    {
        return $this->commissions->register(User::factory()->create(), $rateBps, $parent);
    }

    private function balance(Partner $partner): string
    {
        $wallet = Wallet::query()->where('user_id', $partner->user_id)->where('currency', Currency::USD)->first();

        return $wallet?->balance()->toDecimal() ?? '0.00';
    }
}
