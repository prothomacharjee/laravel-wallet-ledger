<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Withdrawal\SendWithdrawal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class WithdrawalEndpointTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_withdraws_through_the_gateway(): void
    {
        $this->walletFor($this->user, balance: '50.00');

        $this->postJson('/api/withdrawals', ['gateway' => 'fake-fiat', 'amount' => '20.00', 'currency' => 'USD', 'destination' => 'acct-123'])
            ->assertCreated()
            ->assertJson(['gateway' => 'fake-fiat', 'status' => 'completed', 'amount' => '20.00', 'currency' => 'USD'])
            ->assertJsonPath('provider_transaction_id', fn (string $id) => str_starts_with($id, 'fake-fiat_wd_'));
    }

    public function test_gateway_refusal_returns_422_and_keeps_the_balance(): void
    {
        $wallet = $this->walletFor($this->user, balance: '50.00');

        $this->postJson('/api/withdrawals', ['gateway' => 'fake-fiat', 'amount' => '20.00', 'currency' => 'USD', 'destination' => 'reject-this'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'fake-fiat rejected the withdrawal: destination refused');

        $this->assertSame('50.00', $wallet->refresh()->balance()->toDecimal());
    }

    public function test_queued_withdrawal_is_accepted_as_pending(): void
    {
        Queue::fake();
        $wallet = $this->walletFor($this->user, balance: '50.00');

        $this->postJson('/api/withdrawals/queued', ['gateway' => 'fake-fiat', 'amount' => '20.00', 'currency' => 'USD', 'destination' => 'acct-123'])
            ->assertAccepted()
            ->assertJson(['status' => 'pending', 'amount' => '20.00', 'currency' => 'USD']);

        $this->assertSame('30.00', $wallet->refresh()->balance()->toDecimal());
        Queue::assertPushed(SendWithdrawal::class, 1);
    }

    public function test_withdrawal_without_a_wallet_is_rejected(): void
    {
        $this->postJson('/api/withdrawals', ['gateway' => 'fake-fiat', 'amount' => '20.00', 'currency' => 'USD', 'destination' => 'acct-123'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You have no USD wallet.');
    }
}
