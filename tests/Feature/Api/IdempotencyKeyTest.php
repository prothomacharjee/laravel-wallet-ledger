<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Transfer\Transfer;
use App\Domain\Wallet\Wallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class IdempotencyKeyTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    private User $user;

    private Wallet $from;

    private Wallet $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $this->from = $this->walletFor($this->user, balance: '50.00');
        $this->to = $this->walletFor();
    }

    public function test_idempotency_key_replays_stored_response(): void
    {
        $first = $this->transfer('10.00', 'key-1')->assertCreated();

        $this->transfer('10.00', 'key-1')
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertExactJson($first->json());

        $this->assertSame(1, Transfer::query()->count());
        $this->assertSame('40.00', $this->from->refresh()->balance()->toDecimal());
    }

    public function test_idempotency_key_with_different_body_returns_422(): void
    {
        $this->transfer('10.00', 'key-1')->assertCreated();

        $this->transfer('20.00', 'key-1')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This Idempotency-Key was already used with a different request.');

        $this->assertSame(1, Transfer::query()->count());
    }

    public function test_key_reserved_by_an_unfinished_request_is_not_run_again(): void
    {
        $this->transfer('10.00', 'key-1')->assertCreated();
        // As if the process died after reserving the key but before storing the response.
        DB::table('idempotency_keys')->update(['response_status' => null, 'response_body' => null]);

        $this->transfer('10.00', 'key-1')->assertConflict();

        $this->assertSame(1, Transfer::query()->count());
    }

    public function test_keys_are_scoped_to_the_user(): void
    {
        $this->transfer('10.00', 'shared-key')->assertCreated();

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->from = $this->walletFor($other, balance: '50.00');

        $this->transfer('10.00', 'shared-key')->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(2, Transfer::query()->count());
    }

    /** @return TestResponse<Response> */
    private function transfer(string $amount, string $key): TestResponse
    {
        return $this->postJson('/api/transfers', [
            'from_wallet_id' => $this->from->id,
            'to_wallet_id' => $this->to->id,
            'amount' => $amount,
            'currency' => 'USD',
        ], ['Idempotency-Key' => $key]);
    }
}
