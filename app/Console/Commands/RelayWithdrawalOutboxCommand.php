<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Withdrawal\SendWithdrawal;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Safety net for the outbox. A message whose job was never queued (Redis
 * down at the time) or ran out of retries is queued again here.
 */
final class RelayWithdrawalOutboxCommand extends Command
{
    /** Leave fresh messages to the job dispatched right after commit. */
    public const GRACE_SECONDS = 60;

    protected $signature = 'withdrawals:relay';

    protected $description = 'Queue outbox withdrawals that have not been sent yet';

    public function handle(ConnectionInterface $db, Dispatcher $bus): int
    {
        $ids = $db->table('withdrawal_outbox')
            ->whereNull('processed_at')
            ->where('created_at', '<=', now()->subSeconds(self::GRACE_SECONDS))
            ->orderBy('id')
            ->limit(500)
            ->pluck('id');

        foreach ($ids as $id) {
            $bus->dispatch(new SendWithdrawal((int) $id));
        }

        $this->info("Queued {$ids->count()} outbox withdrawal(s).");

        return self::SUCCESS;
    }
}
