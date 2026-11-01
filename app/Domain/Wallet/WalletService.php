<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Ledger\LedgerAccount;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Models\User;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use LogicException;

final readonly class WalletService
{
    public function __construct(
        private ConnectionInterface $db,
        private Config $config,
    ) {}

    /** Returns the user's wallet for this currency, opening it (and its ledger account) if needed. */
    public function open(User $user, Currency $currency): Wallet
    {
        return $this->db->transaction(function () use ($user, $currency): Wallet {
            $existing = Wallet::query()->where('user_id', $user->id)->where('currency', $currency)->first();
            if ($existing !== null) {
                return $existing;
            }

            $account = LedgerAccount::create(['code' => "wallet:{$user->id}:{$currency->value}", 'currency' => $currency]);

            $wallet = new Wallet;
            $wallet->forceFill([
                'user_id' => $user->id,
                'currency' => $currency,
                'balance' => 0,
                'ledger_account_id' => $account->id,
            ])->save();

            return $wallet;
        });
    }

    /**
     * Locks the wallet row until the surrounding transaction ends. Every
     * balance check must happen after this call, never before it.
     */
    public function lock(int $walletId): Wallet
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Wallets can only be locked inside a database transaction.');
        }

        return Wallet::query()->whereKey($walletId)->lockForUpdate()->firstOrFail();
    }

    /** @param Wallet $wallet a row locked by lock() in the current transaction */
    public function credit(Wallet $wallet, Money $amount): void
    {
        $newBalance = $wallet->balance()->plus($amount);
        $cap = $this->maxBalance($wallet->currency);

        if ($newBalance->greaterThan($cap)) {
            throw BalanceCapExceeded::for($wallet, $cap);
        }

        $this->store($wallet, $newBalance);
    }

    /** @param Wallet $wallet a row locked by lock() in the current transaction */
    public function debit(Wallet $wallet, Money $amount): void
    {
        $newBalance = $wallet->balance()->minus($amount);

        if ($newBalance->isNegative()) {
            throw InsufficientFunds::in($wallet, $amount);
        }

        $this->store($wallet, $newBalance);
    }

    public function maxBalance(Currency $currency): Money
    {
        return Money::of($this->config->string("wallet.max_balance.{$currency->value}"), $currency);
    }

    private function store(Wallet $wallet, Money $balance): void
    {
        $wallet->forceFill(['balance' => $balance->amount])->save();
    }
}
