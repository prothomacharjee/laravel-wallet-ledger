<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class TransferEndpointTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create();
        Sanctum::actingAs($this->alice);
    }

    public function test_user_can_transfer_from_own_wallet(): void
    {
        $from = $this->walletFor($this->alice, balance: '20.00');
        $to = $this->walletFor();

        $this->postJson('/api/transfers', [
            'from_wallet_id' => $from->id,
            'to_wallet_id' => $to->id,
            'amount' => '7.50',
            'currency' => 'USD',
        ])
            ->assertCreated()
            ->assertJson(['amount' => '7.50', 'currency' => 'USD']);

        $this->assertSame('12.50', $from->refresh()->balance()->toDecimal());
        $this->assertSame('7.50', $to->refresh()->balance()->toDecimal());
    }

    public function test_user_cannot_transfer_from_someone_elses_wallet(): void
    {
        $notMine = $this->walletFor(balance: '20.00');

        $this->postJson('/api/transfers', [
            'from_wallet_id' => $notMine->id,
            'to_wallet_id' => $this->walletFor($this->alice)->id,
            'amount' => '1.00',
            'currency' => 'USD',
        ])->assertForbidden();

        $this->assertSame('20.00', $notMine->refresh()->balance()->toDecimal());
    }

    public function test_amount_with_too_many_decimals_is_rejected(): void
    {
        $this->postJson('/api/transfers', [
            'from_wallet_id' => $this->walletFor($this->alice, balance: '20.00')->id,
            'to_wallet_id' => $this->walletFor()->id,
            'amount' => '1.001',
            'currency' => 'USD',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_insufficient_funds_returns_422(): void
    {
        $this->postJson('/api/transfers', [
            'from_wallet_id' => $this->walletFor($this->alice, balance: '1.00')->id,
            'to_wallet_id' => $this->walletFor()->id,
            'amount' => '2.00',
            'currency' => 'USD',
        ])->assertUnprocessable()->assertJsonPath('message', fn (string $m) => str_contains($m, 'insufficient funds'));
    }

    public function test_second_submit_while_first_is_in_flight_is_rejected_before_touching_the_database(): void
    {
        $from = $this->walletFor($this->alice, balance: '20.00');
        $inFlight = Cache::lock('submit-lock:'.$this->alice->id, 10);
        $this->assertTrue($inFlight->get());

        $this->postJson('/api/transfers', [
            'from_wallet_id' => $from->id,
            'to_wallet_id' => $this->walletFor()->id,
            'amount' => '1.00',
            'currency' => 'USD',
        ])->assertTooManyRequests();

        $this->assertSame('20.00', $from->refresh()->balance()->toDecimal());
        $inFlight->release();
    }
}
