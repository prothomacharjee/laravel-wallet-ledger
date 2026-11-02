<?php

declare(strict_types=1);

namespace App\Domain\Commission;

use App\Domain\Money\Money;
use App\Models\User;

/**
 * Registers partners and records the fees they earn commission on.
 * Payment happens later, in batches, in CommissionPayer.
 */
final readonly class CommissionService
{
    /** A sub-partner earns at least 5 percentage points less than its parent. */
    public const MIN_GAP_BPS = 500;

    public function register(User $user, int $rateBps, ?Partner $parent = null): Partner
    {
        if ($rateBps < 1 || $rateBps > 10_000) {
            throw CommissionNotAllowed::rateOutOfRange($rateBps);
        }

        if ($parent !== null) {
            if ($parent->parent_id !== null) {
                throw CommissionNotAllowed::chainTooDeep();
            }

            if ($rateBps > $parent->rate_bps - self::MIN_GAP_BPS) {
                throw CommissionNotAllowed::rateTooCloseToParent($rateBps, $parent->rate_bps, self::MIN_GAP_BPS);
            }
        }

        $partner = new Partner;
        $partner->forceFill(['user_id' => $user->id, 'parent_id' => $parent?->id, 'rate_bps' => $rateBps])->save();

        return $partner;
    }

    /** Records a fee once per source reference. Calling it again returns the existing accrual. */
    public function accrue(Partner $partner, Money $fee, string $sourceReference): CommissionAccrual
    {
        if (! $fee->isPositive()) {
            throw CommissionNotAllowed::feeNotPositive();
        }

        CommissionAccrual::query()->insertOrIgnore([
            'partner_id' => $partner->id,
            'source_reference' => $sourceReference,
            'fee_amount' => $fee->amount,
            'currency' => $fee->currency->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return CommissionAccrual::query()->where('source_reference', $sourceReference)->firstOrFail();
    }

    /**
     * Splits a fee across the chain. The earning partner gets its own rate;
     * its parent gets the difference up to the parent's rate. Both shares are
     * cut from one rounded-down total, so the sum never exceeds the top rate.
     *
     * @return list<array{Partner, Money}> shares with a positive amount, earning partner first
     */
    public function split(Partner $partner, Money $fee): array
    {
        $parent = $partner->parent;
        $total = self::share($fee, $parent->rate_bps ?? $partner->rate_bps);
        $own = self::share($fee, $partner->rate_bps);

        $shares = [[$partner, $own]];
        if ($parent !== null) {
            $shares[] = [$parent, $total->minus($own)];
        }

        return array_values(array_filter($shares, fn (array $share): bool => $share[1]->isPositive()));
    }

    /** floor($fee * $rateBps / 10000) without overflowing 64 bits. */
    private static function share(Money $fee, int $rateBps): Money
    {
        $amount = intdiv($fee->amount, 10_000) * $rateBps + intdiv(($fee->amount % 10_000) * $rateBps, 10_000);

        return Money::ofMinor($amount, $fee->currency);
    }
}
