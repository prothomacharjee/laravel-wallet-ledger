<?php

declare(strict_types=1);

namespace App\Domain\Withdrawal;

enum WithdrawalStatus: string
{
    /** Debited, payout not sent yet (outbox only). */
    case Pending = 'pending';
    case Completed = 'completed';
    /** The gateway refused the payout and the wallet was refunded (outbox only). */
    case Failed = 'failed';
}
