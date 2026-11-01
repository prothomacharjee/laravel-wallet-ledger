<?php

declare(strict_types=1);

namespace App\Domain\Withdrawal;

use App\Domain\Money\Money;
use App\Domain\Wallet\Wallet;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Per-user daily and monthly withdrawal limits. A user has one wallet per
 * currency, so counting by wallet counts by user.
 */
final readonly class WithdrawalLimits
{
    public function __construct(private Config $config) {}

    /**
     * @param  Wallet  $wallet  a row locked in the current transaction. Every withdrawal
     *                          from it queues behind that lock, so two cannot both pass
     *                          this check with the same headroom.
     */
    public function check(Wallet $wallet, Money $amount): void
    {
        $currency = $amount->currency;

        foreach (['daily' => now()->startOfDay(), 'monthly' => now()->startOfMonth()] as $period => $since) {
            $limit = Money::of($this->config->string("wallet.withdrawal_limits.{$currency->value}.{$period}"), $currency);

            $used = Money::ofMinor((int) Withdrawal::query()
                ->where('wallet_id', $wallet->id)
                ->where('status', '!=', WithdrawalStatus::Failed)
                ->where('created_at', '>=', $since)
                ->sum('amount'), $currency);

            if ($used->plus($amount)->greaterThan($limit)) {
                throw WithdrawalNotAllowed::limitReached($period, $limit, $used);
            }
        }
    }
}
