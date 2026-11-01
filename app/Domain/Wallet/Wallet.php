<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Ledger\LedgerAccount;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The balance column is a cached copy of the wallet's ledger account balance.
 * It is only changed by WalletService, on a row locked with SELECT ... FOR
 * UPDATE, in the same transaction as the matching ledger posting.
 *
 * @property int $id
 * @property int $user_id
 * @property Currency $currency
 * @property int $balance
 * @property int $ledger_account_id
 * @property-read LedgerAccount $ledgerAccount
 */
class Wallet extends Model
{
    protected $guarded = ['*'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function balance(): Money
    {
        return Money::ofMinor($this->balance, $this->currency);
    }

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'balance' => 'integer',
        ];
    }
}
