<?php

declare(strict_types=1);

namespace Tests\Integration\Deposit;

use App\Domain\Deposit\DepositMatcher;
use App\Domain\Deposit\DepositStatus;
use App\Domain\Ledger\LedgerEntry;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Money\Currency;
use App\Domain\Wallet\Wallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\MakesDeposits;
use Tests\TestCase;

class DepositMatcherTest extends TestCase
{
    use MakesDeposits;
    use RefreshDatabase;

    public function test_deposit_matcher_settles_missed_deposits_idempotently(): void
    {
        $user = User::factory()->create();
        $paid = $this->pendingDeposit($user, '40.00');
        $unpaid = $this->pendingDeposit($user, '0.10000000', Currency::BTC, 'fake-crypto');
        $payment = $this->gateway()->simulatePayment($paid->reference);
        $matcher = $this->app->make(DepositMatcher::class);

        $this->assertSame(['checked' => 1, 'settled' => 1, 'failed' => 0], $matcher->run());
        $this->assertSame(['checked' => 1, 'settled' => 0, 'failed' => 0], $matcher->run());

        // The lost webhook finally arrives after the matcher already settled it.
        $this->sendWebhook('fake-fiat', $this->gateway()->webhookBody($payment))->assertAccepted();

        $this->assertSame(DepositStatus::Settled, $paid->refresh()->status);
        $this->assertSame($payment->transactionId, $paid->provider_transaction_id);
        $this->assertSame(DepositStatus::Pending, $unpaid->refresh()->status);
        $this->assertSame('40.00', Wallet::query()->findOrFail($paid->wallet_id)->balance()->toDecimal());
        $this->assertSame(2, LedgerEntry::query()->where('reference_type', 'deposit')->count());
        $this->assertSame([], $this->app->make(LedgerVerifier::class)->problems());
    }

    public function test_matcher_is_scheduled_every_thirty_seconds(): void
    {
        Artisan::call('schedule:list');
        $line = collect(explode("\n", Artisan::output()))->first(fn (string $l) => str_contains($l, 'deposits:match'));

        $this->assertNotNull($line, 'deposits:match is not scheduled.');
        $this->assertMatchesRegularExpression('/\* \* \* \* \*\s+30s\s+php artisan deposits:match/', $line);
    }
}
