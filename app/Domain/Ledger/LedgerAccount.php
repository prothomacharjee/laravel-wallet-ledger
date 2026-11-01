<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property Currency $currency
 */
#[Fillable(['code', 'currency'])]
class LedgerAccount extends Model
{
    /** Finds or opens a system account (gateway clearing etc.). Safe under concurrency. */
    public static function forCode(string $code, Currency $currency): self
    {
        static::query()->insertOrIgnore([
            'code' => $code,
            'currency' => $currency->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return static::query()->where('code', $code)->firstOrFail();
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** Credits minus debits. Customer wallets are positive; clearing accounts go negative. */
    public function balance(): Money
    {
        $net = $this->entries()
            ->selectRaw("COALESCE(SUM(CASE direction WHEN 'credit' THEN amount ELSE -CAST(amount AS SIGNED) END), 0) AS net")
            ->value('net');

        return Money::ofMinor((int) $net, $this->currency);
    }

    protected function casts(): array
    {
        return ['currency' => Currency::class];
    }
}
