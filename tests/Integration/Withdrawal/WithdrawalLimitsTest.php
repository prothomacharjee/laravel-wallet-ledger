<?php

declare(strict_types=1);

namespace Tests\Integration\Withdrawal;

use App\Domain\Money\Money;
use App\Domain\Wallet\Wallet;
use App\Domain\Withdrawal\WithdrawalNotAllowed;
use App\Domain\Withdrawal\WithdrawalService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class WithdrawalLimitsTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    private User $user;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wallet.withdrawal_limits.USD.daily' => '100.00',
            'wallet.withdrawal_limits.USD.monthly' => '250.00',
        ]);
        $this->travelTo('2026-11-10 09:00:00');

        $this->user = User::factory()->create();
        $this->wallet = $this->walletFor($this->user, balance: '1000.00');
    }

    public function test_daily_withdrawal_limit_is_enforced(): void
    {
        $this->withdraw('60.00');
        $this->withdraw('40.00');

        $this->assertRejected('0.01', 'Daily withdrawal limit is 100.00 USD; 100.00 already withdrawn this day.');
        $this->assertSame('900.00', $this->wallet->refresh()->balance()->toDecimal());

        $this->travelTo('2026-11-11 00:00:00');
        $this->withdraw('100.00');
    }

    public function test_monthly_withdrawal_limit_is_enforced(): void
    {
        $this->withdraw('100.00');
        $this->travelTo('2026-11-11 09:00:00');
        $this->withdraw('100.00');
        $this->travelTo('2026-11-12 09:00:00');

        $this->assertRejected('50.01', 'Monthly withdrawal limit is 250.00 USD; 200.00 already withdrawn this month.');

        $this->travelTo('2026-12-01 00:00:00');
        $this->withdraw('100.00');
        $this->assertSame('700.00', $this->wallet->refresh()->balance()->toDecimal());
    }

    private function withdraw(string $amount): void
    {
        $this->app->make(WithdrawalService::class)->withdraw($this->user, 'fake-fiat', $this->usd($amount), 'acct-123');
    }

    private function assertRejected(string $amount, string $message): void
    {
        try {
            $this->withdraw($amount);
            $this->fail('The withdrawal should be over the limit.');
        } catch (WithdrawalNotAllowed $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function usd(string $amount): Money
    {
        return Money::of($amount, $this->wallet->currency);
    }
}
