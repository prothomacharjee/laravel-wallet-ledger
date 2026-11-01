<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

enum DepositStatus: string
{
    case Pending = 'pending';
    case Settled = 'settled';
}
