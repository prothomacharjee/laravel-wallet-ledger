<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $from_wallet_id
 * @property int $to_wallet_id
 * @property int $amount
 * @property Currency $currency
 */
class Transfer extends Model
{
    protected $guarded = ['*'];

    public function money(): Money
    {
        return Money::ofMinor($this->amount, $this->currency);
    }

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'amount' => 'integer',
        ];
    }
}
