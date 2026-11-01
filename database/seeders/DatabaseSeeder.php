<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Ledger\LedgerAccount;
use App\Domain\Ledger\LedgerWriter;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    private const DEMO_USERS = 20;

    private const OPENING_BALANCE = '500.00';

    /** Demo users demo1@example.com ... demo20@example.com (password "password"), each with a funded USD wallet. */
    public function run(ConnectionInterface $db, WalletService $wallets, LedgerWriter $ledger): void
    {
        $funding = LedgerAccount::firstOrCreate(['code' => 'opening-balance:USD'], ['currency' => Currency::USD]);
        $amount = Money::of(self::OPENING_BALANCE, Currency::USD);

        for ($i = 1; $i <= self::DEMO_USERS; $i++) {
            $user = User::factory()->create(['name' => "Demo User {$i}", 'email' => "demo{$i}@example.com"]);
            $wallet = $wallets->open($user, Currency::USD);

            // Opening balances go through the ledger like any other money, so ledger:verify holds from the start.
            $db->transaction(function () use ($wallets, $ledger, $wallet, $funding, $amount): void {
                $locked = $wallets->lock($wallet->id);
                $wallets->credit($locked, $amount);
                $ledger->post('opening-balance', (string) $locked->id, $funding, $locked->ledgerAccount, $amount);
            });
        }
    }
}
