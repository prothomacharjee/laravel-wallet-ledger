<?php

declare(strict_types=1);

namespace App\Domain\Withdrawal;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queued only after the debit commits. Running it twice is harmless, so the
 * relay may re-queue a message that is still in flight.
 */
final class SendWithdrawal implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(public readonly int $outboxId) {}

    public function handle(OutboxWithdrawalService $withdrawals): void
    {
        $withdrawals->send($this->outboxId);
    }
}
