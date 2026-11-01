<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Ledger\LedgerAccount;
use App\Domain\Ledger\LedgerWriter;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Wallet\Wallet;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesWallets
{
    protected function walletFor(?User $user = null, Currency $currency = Currency::USD, string $balance = '0'): Wallet
    {
        $wallet = $this->app->make(WalletService::class)->open($user ?? User::factory()->create(), $currency);

        if ($balance !== '0') {
            $this->fund($wallet, Money::of($balance, $currency));
        }

        return $wallet->refresh();
    }

    /** Credits a wallet from a test clearing account through the real ledger, so ledger:verify stays green. */
    protected function fund(Wallet $wallet, Money $amount): void
    {
        DB::transaction(function () use ($wallet, $amount): void {
            $wallets = $this->app->make(WalletService::class);
            $locked = $wallets->lock($wallet->id);
            $wallets->credit($locked, $amount);

            $clearing = LedgerAccount::firstOrCreate(
                ['code' => "test-funding:{$amount->currency->value}"],
                ['currency' => $amount->currency],
            );
            $this->app->make(LedgerWriter::class)
                ->post('test-funding', (string) Str::uuid(), $clearing, $locked->ledgerAccount, $amount);
        });
    }
}
