<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $wallet_id
 * @property string $gateway
 * @property string $reference
 * @property int $amount
 * @property Currency $currency
 * @property DepositStatus $status
 * @property string|null $provider_transaction_id
 * @property Carbon|null $settled_at
 */
class Deposit extends Model
{
    protected $guarded = ['*'];

    public function money(): Money
    {
        return Money::ofMinor($this->amount, $this->currency);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'currency' => Currency::class,
            'status' => DepositStatus::class,
            'settled_at' => 'datetime',
        ];
    }
}
