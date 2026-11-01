<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Domain\Money\Currency;

final class FakeCryptoGateway extends FakeGateway
{
    public function name(): string
    {
        return 'fake-crypto';
    }

    protected function currencies(): array
    {
        return [Currency::BTC, Currency::USDT];
    }
}
