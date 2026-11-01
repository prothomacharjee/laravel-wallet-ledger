<?php

declare(strict_types=1);

namespace App\Domain\Withdrawal;

enum WithdrawalStatus: string
{
    case Completed = 'completed';
}
