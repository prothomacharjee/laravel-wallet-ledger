<?php

declare(strict_types=1);

namespace App\Domain\Commission;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $partner_id
 * @property string $source_reference
 * @property int $fee_amount
 * @property Currency $currency
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $processed_at
 * @property-read Partner $partner
 */
class CommissionAccrual extends Model
{
    protected $guarded = ['*'];

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function fee(): Money
    {
        return Money::ofMinor($this->fee_amount, $this->currency);
    }

    protected function casts(): array
    {
        return [
            'fee_amount' => 'integer',
            'currency' => Currency::class,
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}
