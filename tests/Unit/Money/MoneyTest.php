<?php

declare(strict_types=1);

namespace Tests\Unit\Money;

use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatch;
use App\Domain\Money\Money;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_decimal_string_is_stored_as_integer_minor_units(): void
    {
        $this->assertSame(1050, Money::of('10.50', Currency::USD)->amount);
        $this->assertSame(1, Money::of('0.00000001', Currency::BTC)->amount);
        $this->assertSame(-250, Money::of('-2.5', Currency::BDT)->amount);
        $this->assertSame(7_000_000, Money::of('7', Currency::USDT)->amount);
    }

    public function test_minor_units_format_back_to_an_exact_decimal_string(): void
    {
        $this->assertSame('10.50', Money::ofMinor(1050, Currency::USD)->toDecimal());
        $this->assertSame('0.00000001', Money::ofMinor(1, Currency::BTC)->toDecimal());
        $this->assertSame('-0.05', Money::ofMinor(-5, Currency::USD)->toDecimal());
        $this->assertSame('0.000000', Money::zero(Currency::USDT)->toDecimal());
    }

    /** @return iterable<string, array{string, Currency}> */
    public static function invalidAmounts(): iterable
    {
        yield 'more decimals than the currency allows' => ['1.005', Currency::USD];
        yield 'scientific notation' => ['1e3', Currency::USD];
        yield 'empty string' => ['', Currency::USD];
        yield 'thousands separator' => ['1,000', Currency::USD];
        yield 'trailing dot' => ['10.', Currency::USD];
        yield 'leading plus' => ['+10', Currency::USD];
    }

    #[DataProvider('invalidAmounts')]
    public function test_ambiguous_or_over_precise_amounts_are_rejected_not_rounded(string $amount, Currency $currency): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of($amount, $currency);
    }

    public function test_amount_that_does_not_fit_in_64_bits_is_rejected(): void
    {
        $this->expectException(OverflowException::class);

        Money::of('92233720368547758.08', Currency::USD);
    }

    public function test_addition_and_subtraction_are_exact(): void
    {
        $a = Money::of('0.10', Currency::USD);
        $b = Money::of('0.20', Currency::USD);

        $this->assertSame('0.30', $a->plus($b)->toDecimal());
        $this->assertSame('-0.10', $a->minus($b)->toDecimal());
    }

    public function test_arithmetic_overflow_throws_instead_of_turning_into_a_float(): void
    {
        $this->expectException(OverflowException::class);

        Money::ofMinor(PHP_INT_MAX, Currency::USD)->plus(Money::ofMinor(1, Currency::USD));
    }

    public function test_mixing_currencies_is_refused(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of('1', Currency::USD)->plus(Money::of('1', Currency::BDT));
    }

    public function test_comparisons(): void
    {
        $five = Money::of('5', Currency::USD);
        $ten = Money::of('10', Currency::USD);

        $this->assertTrue($ten->greaterThan($five));
        $this->assertFalse($five->greaterThan($ten));
        $this->assertTrue($five->greaterThanOrEqual(Money::of('5.00', Currency::USD)));
        $this->assertTrue($five->equals(Money::ofMinor(500, Currency::USD)));
        $this->assertFalse($five->equals(Money::ofMinor(500, Currency::BDT)));
        $this->assertTrue($five->minus($ten)->isNegative());
        $this->assertTrue(Money::zero(Currency::USD)->isZero());
        $this->assertTrue($five->isPositive());
    }

    public function test_money_is_immutable(): void
    {
        $original = Money::of('1', Currency::USD);
        $original->plus(Money::of('1', Currency::USD));

        $this->assertSame('1.00', $original->toDecimal());
    }
}
