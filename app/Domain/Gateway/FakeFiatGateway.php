<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Domain\Money\Currency;

final class FakeFiatGateway extends FakeGateway
{
    public function name(): string
    {
        return 'fake-fiat';
    }

    protected function currencies(): array
    {
        return [Currency::USD, Currency::BDT];
    }
}
