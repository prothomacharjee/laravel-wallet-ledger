<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Gateway\GatewayRegistry;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class DepositRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(GatewayRegistry $gateways): array
    {
        return [
            'gateway' => ['required', 'string', Rule::in($gateways->names())],
            'currency' => ['required', Rule::enum(Currency::class)],
            'amount' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $currency = $this->enum('currency', Currency::class);
                if ($currency === null) {
                    return;
                }
                try {
                    if (! Money::of((string) $value, $currency)->isPositive()) {
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
        return Money::of($this->string('amount')->value(), $this->enum('currency', Currency::class) ?? Currency::USD);
    }
}
