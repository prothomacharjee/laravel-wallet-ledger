# ADR 0001: Money as integer minor units with a per-currency scale

Status: accepted

## Context

Every balance, ledger entry, deposit and withdrawal in this system is an amount of money. The wallets hold both fiat (USD, BDT) and crypto (BTC, USDT), so the number of decimal places differs per currency: 2 for fiat, 6 for USDT, 8 for BTC.

Floats are not an option: `0.1 + 0.2` is not `0.3` in binary floating point, and a ledger that is off by one satoshi does not balance.

That leaves two common choices:

1. **`DECIMAL(20,8)` columns** for every amount, with PHP handling the values as strings and doing arithmetic through `bcmath`.
2. **`BIGINT` columns of minor units** (cents, satoshis), with each currency declaring how many decimal places it has.

## Decision

Store every amount as a signed `BIGINT` of minor units. Each `Currency` declares its scale. Amounts move through the code only as the `App\Domain\Money\Money` value object, which pairs the integer with its currency.

`Money` enforces the rules in one place:

- It is built from an `int` of minor units, or parsed from a plain decimal string such as `"10.50"`. A float cannot be passed in (`strict_types`).
- An amount with more decimal places than the currency allows (`"1.005"` USD) is **rejected, not rounded**. Rounding is a business decision, so it must happen on purpose where the business rule lives, never as a side effect of parsing.
- Adding or subtracting two different currencies throws `CurrencyMismatch`.
- PHP silently turns an overflowing `int` into a `float`. `Money` checks every result and throws `OverflowException` instead.
- `Money` is immutable. Every operation returns a new instance.

## Consequences

Good:

- Arithmetic is native integer arithmetic: exact, fast, and the same in PHP and in SQL (`SUM(amount)` is exact).
- The database cannot hold a value finer than the currency allows.
- One class owns the parsing, formatting and overflow rules, so they are tested once (`tests/Unit/Money/MoneyTest.php`).

Costs:

- Every amount column needs its currency next to it to be readable. A raw `1050` means nothing without "USD, scale 2".
- The range is bounded by 64 bits: about 92 billion BTC, or 92 quadrillion USD. That is far beyond any realistic wallet, and overflow throws rather than wraps.
- Changing a currency's scale later means migrating stored amounts. Pick the scale once, at the precision the payment rails actually settle in.
- Percentages (fees, commissions) produce fractions of a minor unit. Those calculations must round explicitly with a named rule, which is the intended effect.

Rejected: `DECIMAL(20,8)` gives one uniform column type, but every PHP operation then needs `bcmath` on strings, and one careless `(float)` cast reintroduces the bug the type was meant to prevent. It also stores 8 decimal places for currencies that settle in 2, which allows amounts that cannot exist on the payment rails.
