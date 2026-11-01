<?php

declare(strict_types=1);

namespace Tests\Integration\Withdrawal;

use App\Domain\Gateway\GatewayRejected;
use App\Domain\Ledger\LedgerEntry;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Money\Money;
use App\Domain\Wallet\InsufficientFunds;
use App\Domain\Wallet\Wallet;
use App\Domain\Withdrawal\Withdrawal;
use App\Domain\Withdrawal\WithdrawalService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class WithdrawalServiceTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    private WithdrawalService $withdrawals;

    private User $user;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withdrawals = $this->app->make(WithdrawalService::class);
        $this->user = User::factory()->create();
        $this->wallet = $this->walletFor($this->user, balance: '100.00');
    }

    public function test_withdrawal_debits_wallet_writes_balanced_ledger_pair_and_pays_out(): void
    {
        $withdrawal = $this->withdrawals->withdraw($this->user, 'fake-fiat', $this->usd('30.00'), 'acct-123');

        $this->assertSame('70.00', $this->wallet->refresh()->balance()->toDecimal());
        $this->assertNotNull($withdrawal->provider_transaction_id);
        $this->assertSame(2, LedgerEntry::query()->where('reference_type', 'withdrawal')->where('reference_id', (string) $withdrawal->id)->count());
        $this->assertDatabaseHas('fake_gateway_withdrawals', [
            'reference' => $withdrawal->reference,
            'transaction_id' => $withdrawal->provider_transaction_id,
            'amount' => 3000,
            'status' => 'accepted',
        ], 'gateway');
        $this->assertSame([], $this->app->make(LedgerVerifier::class)->problems());
    }

    public function test_withdrawal_rolls_back_when_gateway_rejects(): void
    {
        try {
            $this->withdrawals->withdraw($this->user, 'fake-fiat', $this->usd('30.00'), 'reject-me');
            $this->fail('The gateway refusal should surface.');
        } catch (GatewayRejected) {
        }

        $this->assertSame('100.00', $this->wallet->refresh()->balance()->toDecimal());
        $this->assertSame(0, Withdrawal::query()->count());
        $this->assertSame(0, LedgerEntry::query()->where('reference_type', 'withdrawal')->count());
        $this->assertSame(0, DB::table('withdrawal_cancellations')->count());
        $this->assertSame([], $this->app->make(LedgerVerifier::class)->problems());
    }

    public function test_withdrawal_sends_cancel_when_commit_fails_after_gateway_accepts(): void
    {
        // The only local write after the gateway call stores its transaction ID,
        // right before COMMIT. Failing it stands in for a failed commit.
        Withdrawal::updating(fn () => throw new RuntimeException('database went away during commit'));

        try {
            $this->withdrawals->withdraw($this->user, 'fake-fiat', $this->usd('30.00'), 'acct-123');
            $this->fail('The commit failure should surface.');
        } catch (RuntimeException $e) {
            $this->assertSame('database went away during commit', $e->getMessage());
        }

        $this->assertSame('100.00', $this->wallet->refresh()->balance()->toDecimal());
        $this->assertSame(0, Withdrawal::query()->count());

        $cancellation = DB::table('withdrawal_cancellations')->sole();
        $this->assertSame('database went away during commit', $cancellation->reason);
        $this->assertSame(1, (int) $cancellation->cancel_accepted);
        $this->assertDatabaseHas('fake_gateway_withdrawals', [
            'transaction_id' => $cancellation->provider_transaction_id,
            'status' => 'cancelled',
        ], 'gateway');
        $this->assertSame([], $this->app->make(LedgerVerifier::class)->problems());
    }

    public function test_withdrawal_with_insufficient_funds_never_reaches_the_gateway(): void
    {
        try {
            $this->withdrawals->withdraw($this->user, 'fake-fiat', $this->usd('100.01'), 'acct-123');
            $this->fail('The overdraft should be refused.');
        } catch (InsufficientFunds) {
        }

        $this->assertSame(0, DB::connection('gateway')->table('fake_gateway_withdrawals')->count());
        $this->assertSame('100.00', $this->wallet->refresh()->balance()->toDecimal());
    }

    private function usd(string $amount): Money
    {
        return Money::of($amount, $this->wallet->currency);
    }
}
