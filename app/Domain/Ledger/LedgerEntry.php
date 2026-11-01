<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Money\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a balanced posting. Rows are written only by LedgerWriter and
 * never change afterwards; a database trigger enforces the same rule.
 *
 * @property int $id
 * @property int $ledger_account_id
 * @property string $reference_type
 * @property string $reference_id
 * @property Direction $direction
 * @property int $amount
 * @property Currency $currency
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /** @return BelongsTo<LedgerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw LedgerEntryIsImmutable::create());
        static::deleting(fn () => throw LedgerEntryIsImmutable::create());
    }

    protected function casts(): array
    {
        return [
            'direction' => Direction::class,
            'currency' => Currency::class,
            'amount' => 'integer',
        ];
    }
}
