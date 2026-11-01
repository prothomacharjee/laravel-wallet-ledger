<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Deposit\SettlementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWallets;
use Tests\Concerns\MakesDeposits;
use Tests\TestCase;

class DepositEndpointTest extends TestCase
{
    use CreatesWallets;
    use MakesDeposits;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_creates_a_pending_deposit_registered_with_the_gateway(): void
    {
        $reference = $this->postJson('/api/deposits', ['gateway' => 'fake-crypto', 'amount' => '0.01500000', 'currency' => 'BTC'])
            ->assertCreated()
            ->assertJson(['gateway' => 'fake-crypto', 'status' => 'pending', 'amount' => '0.01500000', 'currency' => 'BTC'])
            ->json('reference');

        $this->assertDatabaseHas('fake_gateway_payments', ['deposit_reference' => $reference, 'amount' => 1_500_000]);
    }

    public function test_only_one_pending_deposit_per_payment_method(): void
    {
        $request = ['gateway' => 'fake-fiat', 'amount' => '10.00', 'currency' => 'USD'];

        $first = $this->postJson('/api/deposits', $request)->assertCreated()->json('reference');
        $this->postJson('/api/deposits', $request)
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'already have a pending deposit'));

        $this->postJson('/api/deposits', ['gateway' => 'fake-crypto', 'amount' => '5.000000', 'currency' => 'USDT'])
            ->assertCreated();

        $this->app->make(SettlementService::class)->settle($this->gateway()->simulatePayment($first));
        $this->postJson('/api/deposits', $request)->assertCreated();

        $this->assertSame(2, DB::table('deposits')->where('gateway', 'fake-fiat')->count());
    }

    public function test_deposit_rejected_when_balance_cap_would_be_exceeded(): void
    {
        config(['wallet.max_balance.USD' => '100.00']);
        $this->walletFor($this->user, balance: '90.00');

        $this->postJson('/api/deposits', ['gateway' => 'fake-fiat', 'amount' => '10.01', 'currency' => 'USD'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'maximum balance'));

        $this->assertSame(0, DB::table('deposits')->count());
        $this->assertSame(0, DB::table('fake_gateway_payments')->count());
    }

    public function test_gateway_must_support_the_currency(): void
    {
        $this->postJson('/api/deposits', ['gateway' => 'fake-crypto', 'amount' => '10.00', 'currency' => 'USD'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'fake-crypto does not accept USD.');
    }
}
