<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Wallet\Wallet;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class TransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Wallet::query()
            ->whereKey($this->integer('from_wallet_id'))
            ->where('user_id', $this->user()?->getAuthIdentifier())
            ->exists();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from_wallet_id' => ['required', 'integer'],
            'to_wallet_id' => ['required', 'integer', 'exists:wallets,id'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'amount' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    $money = Money::of((string) $value, $this->currency());
                    if (! $money->isPositive()) {
                        $fail('The amount must be greater than zero.');
                    }
                } catch (InvalidArgumentException) {
                    $fail('The amount must be a plain decimal with no more places than the currency allows.');
                }
            }],
        ];
    }

    public function money(): Money
    {
        return Money::of($this->string('amount')->value(), $this->currency());
    }

    private function currency(): Currency
    {
        return $this->enum('currency', Currency::class) ?? Currency::USD;
    }
}
