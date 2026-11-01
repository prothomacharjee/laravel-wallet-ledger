<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;
use OverflowException;

/**
 * An exact amount of one currency, held as a signed integer of minor units
 * (cents, satoshis). Floats never enter or leave this class.
 */
final readonly class Money
{
    private function __construct(
        public int $amount,
        public Currency $currency,
    ) {}

    public static function ofMinor(int $amount, Currency $currency): self
    {
        return new self($amount, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Parses a plain decimal string such as "10.50". Amounts with more decimal
     * places than the currency allows are rejected, never rounded.
     */
    public static function of(string $decimal, Currency $currency): self
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $decimal, $m) !== 1) {
            throw new InvalidArgumentException("Invalid amount \"{$decimal}\".");
        }

        $fraction = $m[3] ?? '';
        $scale = $currency->scale();

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException(
                "Amount \"{$decimal}\" has more than {$scale} decimal places for {$currency->value}."
            );
        }

        $digits = ltrim($m[2].str_pad($fraction, $scale, '0'), '0') ?: '0';
        $signed = ($m[1] === '-' && $digits !== '0' ? '-' : '').$digits;
        $amount = filter_var($signed, FILTER_VALIDATE_INT);

        if ($amount === false) {
            throw new OverflowException("Amount \"{$decimal}\" does not fit in 64 bits.");
        }

        return new self($amount, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->amount + $other->amount), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->amount - $other->amount), $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount >= $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function toDecimal(): string
    {
        $scale = $this->currency->scale();
        $digits = str_pad(ltrim((string) $this->amount, '-'), $scale + 1, '0', STR_PAD_LEFT);
        $sign = $this->amount < 0 ? '-' : '';

        return $sign.substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }

    /** PHP silently turns an overflowing int into a float; refuse that. */
    private static function checked(int|float $result): int
    {
        if (! is_int($result)) {
            throw new OverflowException('Money arithmetic overflowed 64 bits.');
        }

        return $result;
    }
}
