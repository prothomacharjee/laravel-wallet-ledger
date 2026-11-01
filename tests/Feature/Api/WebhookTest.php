<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Deposit\DepositStatus;
use App\Domain\Gateway\SignatureVerifier;
use App\Domain\Ledger\LedgerEntry;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Wallet\Wallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesDeposits;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use MakesDeposits;
    use RefreshDatabase;

    public function test_same_webhook_delivered_five_times_credits_once(): void
    {
        $deposit = $this->pendingDeposit(User::factory()->create(), '25.00');
        $body = $this->gateway()->webhookBody($this->gateway()->simulatePayment($deposit->reference));

        for ($i = 0; $i < 5; $i++) {
            $this->sendWebhook('fake-fiat', $body)->assertAccepted();
        }

        $this->assertSame('25.00', Wallet::query()->findOrFail($deposit->wallet_id)->balance()->toDecimal());
        $this->assertSame(DepositStatus::Settled, $deposit->refresh()->status);
        $this->assertSame(2, LedgerEntry::query()->where('reference_type', 'deposit')->count());
        $this->assertSame(
            ['processed' => 1, 'duplicate' => 4],
            DB::table('webhook_calls')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->orderBy('status', 'desc')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
        );
        $this->assertSame([], $this->app->make(LedgerVerifier::class)->problems());
    }

    public function test_invalid_webhook_signature_is_rejected_and_recorded(): void
    {
        $deposit = $this->pendingDeposit(User::factory()->create());
        $body = $this->gateway()->webhookBody($this->gateway()->simulatePayment($deposit->reference));

        $this->sendWebhook('fake-fiat', $body, str_repeat('0', 64))->assertAccepted();
        // Correctly signed, but with the fiat secret and sent to the crypto endpoint.
        $this->sendWebhook('fake-crypto', $body, SignatureVerifier::sign($body, $this->gateway('fake-fiat')->webhookSecret()))->assertAccepted();

        $this->assertSame(DepositStatus::Pending, $deposit->refresh()->status);
        $this->assertSame(0, Wallet::query()->findOrFail($deposit->wallet_id)->balance);
        $this->assertSame(2, DB::table('webhook_calls')->where('status', 'rejected')->where('signature_valid', false)->count());
    }

    public function test_webhook_returns_202_even_when_settlement_fails(): void
    {
        $deposit = $this->pendingDeposit(User::factory()->create(), '25.00');
        $this->gateway()->simulatePayment($deposit->reference);
        $tampered = (string) json_encode([
            'event' => 'payment.completed',
            'transaction_id' => 'fake-fiat_tampered',
            'reference' => $deposit->reference,
            'amount' => '2500.00',
            'currency' => 'USD',
        ]);

        $this->sendWebhook('fake-fiat', $tampered)->assertAccepted();

        $call = DB::table('webhook_calls')->sole();
        $this->assertSame('failed', $call->status);
        $this->assertStringContainsString('does not match deposit', (string) $call->error);
        $this->assertSame(DepositStatus::Pending, $deposit->refresh()->status);
        $this->assertSame(0, Wallet::query()->findOrFail($deposit->wallet_id)->balance);
    }

    public function test_unparseable_webhook_is_recorded_as_failed(): void
    {
        $this->sendWebhook('fake-fiat', 'not json')->assertAccepted();

        $this->assertSame('failed', DB::table('webhook_calls')->value('status'));
    }
}
