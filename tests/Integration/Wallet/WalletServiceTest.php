<?php

declare(strict_types=1);

namespace Tests\Integration\Wallet;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Wallet\BalanceCapExceeded;
use App\Domain\Wallet\InsufficientFunds;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    public function test_a_user_has_one_wallet_per_currency(): void
    {
        $user = User::factory()->create();
        $service = $this->app->make(WalletService::class);

        $usd = $service->open($user, Currency::USD);

        $this->assertTrue($usd->is($service->open($user, Currency::USD)));
        $this->assertFalse($usd->is($service->open($user, Currency::BTC)));
    }

    public function test_credit_is_rejected_when_balance_cap_would_be_exceeded(): void
    {
        config(['wallet.max_balance.USD' => '100.00']);
        $wallet = $this->walletFor(balance: '90.00');

        try {
            $this->fund($wallet, Money::of('10.01', Currency::USD));
            $this->fail('A credit above the balance cap was accepted.');
        } catch (BalanceCapExceeded) {
            $this->assertSame('90.00', $wallet->refresh()->balance()->toDecimal());
        }

        $this->fund($wallet, Money::of('10.00', Currency::USD));
        $this->assertSame('100.00', $wallet->refresh()->balance()->toDecimal());
    }

    public function test_debit_is_rejected_when_funds_are_insufficient(): void
    {
        $wallet = $this->walletFor(balance: '5.00');
        $service = $this->app->make(WalletService::class);

        $this->expectException(InsufficientFunds::class);

        DB::transaction(fn () => $service->debit($service->lock($wallet->id), Money::of('5.01', Currency::USD)));
    }
}
