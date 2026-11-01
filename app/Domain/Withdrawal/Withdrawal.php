<?php

declare(strict_types=1);

namespace App\Domain\Withdrawal;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int $wallet_id
 * @property string $gateway
 * @property string $reference
 * @property int $amount
 * @property Currency $currency
 * @property string $destination
 * @property WithdrawalStatus $status
 * @property string|null $provider_transaction_id
 */
class Withdrawal extends Model
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
            'status' => WithdrawalStatus::class,
        ];
    }
}
