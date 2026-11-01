<?php

declare(strict_types=1);

namespace Tests\Integration\Withdrawal;

use App\Domain\Gateway\GatewayUnavailable;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Money\Money;
use App\Domain\Wallet\Wallet;
use App\Domain\Withdrawal\OutboxWithdrawalService;
use App\Domain\Withdrawal\SendWithdrawal;
use App\Domain\Withdrawal\Withdrawal;
use App\Domain\Withdrawal\WithdrawalStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class OutboxWithdrawalServiceTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    private OutboxWithdrawalService $withdrawals;

    private User $user;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->withdrawals = $this->app->make(OutboxWithdrawalService::class);
        $this->user = User::factory()->create();
        $this->wallet = $this->walletFor($this->user, balance: '100.00');
    }

    public function test_outbox_withdrawal_debits_at_once_and_pays_out_after_commit(): void
    {
        $withdrawal = $this->request('acct-123');

        $this->assertSame(WithdrawalStatus::Pending, $withdrawal->status);
        $this->assertSame('70.00', $this->wallet->refresh()->balance()->toDecimal());
        $this->assertSame(0, $this->gatewayPayouts());
        Queue::assertPushed(SendWithdrawal::class, 1);

        $this->sendQueued();

        $withdrawal->refresh();
        $this->assertSame(WithdrawalStatus::Completed, $withdrawal->status);
        $this->assertNotNull($withdrawal->provider_transaction_id);
        $this->assertSame(1, $this->gatewayPayouts());
        $this->assertNotNull(DB::table('withdrawal_outbox')->value('processed_at'));
        $this->assertSame([], $this->app->make(LedgerVerifier::class)->problems());
    }

    public function test_outbox_withdrawal_is_refunded_when_gateway_rejects(): void
    {
        $withdrawal = $this->request('reject-me');

        $this->sendQueued();

        $this->assertSame(WithdrawalStatus::Failed, $withdrawal->refresh()->status);
        $this->assertSame('100.00', $this->wallet->refresh()->balance()->toDecimal());
        $this->assertSame('100.00', $this->wallet->ledgerAccount->balance()->toDecimal());
        $this->assertSame('fake-fiat rejected the withdrawal: destination refused', DB::table('withdrawal_outbox')->value('last_error'));
        $this->assertSame([], $this->app->make(LedgerVerifier::class)->problems());
    }

    public function test_outbox_message_sent_twice_pays_out_once(): void
    {
        $withdrawal = $this->request('acct-123');
        $outboxId = (int) DB::table('withdrawal_outbox')->value('id');

        // At-least-once delivery: a worker that crashed after the gateway call
        // runs the message again. The gateway dedupes by our reference.
        $this->withdrawals->send($outboxId);
        DB::table('withdrawal_outbox')->update(['processed_at' => null]);
        $this->withdrawals->send($outboxId);

        $this->assertSame(WithdrawalStatus::Completed, $withdrawal->refresh()->status);
        $this->assertSame(1, $this->gatewayPayouts());
        $this->assertSame('70.00', $this->wallet->refresh()->balance()->toDecimal());
    }

    public function test_gateway_outage_keeps_the_withdrawal_pending_for_retry(): void
    {
        $withdrawal = $this->request('unavailable-for-now');

        try {
            $this->sendQueued();
            $this->fail('The outage should surface so the queue retries the job.');
        } catch (GatewayUnavailable) {
        }

        $this->assertSame(WithdrawalStatus::Pending, $withdrawal->refresh()->status);
        $this->assertSame('70.00', $this->wallet->refresh()->balance()->toDecimal());
        $outbox = DB::table('withdrawal_outbox')->sole();
        $this->assertNull($outbox->processed_at);
        $this->assertSame(1, (int) $outbox->attempts);
    }

    public function test_relay_requeues_unsent_messages(): void
    {
        $this->request('acct-123');
        Queue::fake();

        $this->travel(2)->minutes();
        Artisan::call('withdrawals:relay');

        Queue::assertPushed(SendWithdrawal::class, 1);
    }

    private function request(string $destination): Withdrawal
    {
        return $this->withdrawals->request($this->user, 'fake-fiat', Money::of('30.00', $this->wallet->currency), $destination);
    }

    private function sendQueued(): void
    {
        Queue::pushed(SendWithdrawal::class)->each(fn (SendWithdrawal $job) => $job->handle($this->withdrawals));
    }

    private function gatewayPayouts(): int
    {
        return DB::connection('gateway')->table('fake_gateway_withdrawals')->count();
    }
}
