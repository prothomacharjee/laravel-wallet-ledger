# ADR 0003: Row locks, fixed lock ordering and state checks under lock

Status: accepted

## Context

Two requests that spend from the same wallet at the same moment are the classic double spend. A typical bug looks safe in review:

```php
if ($wallet->balance >= $amount) {   // read
    $wallet->balance -= $amount;     // write, milliseconds later
    $wallet->save();
}
```

Both requests read the same balance, both pass the check, both write. The wallet has paid twice from money it only had once.

Transfers add a second problem. A transfer A to B and a transfer B to A, each locking its sender first, can each hold one lock and wait for the other: a deadlock.

## Decision

1. **Lock, then check, then write, in one transaction.** `WalletService::lock()` runs `SELECT ... FOR UPDATE` on the wallet row. The funds check, the balance-cap check and the currency check all read the locked row. No other transaction can change it until we commit, so what we checked is still true when we write. `lock()` refuses to run outside a transaction, where `FOR UPDATE` would release immediately.
2. **Lowest wallet ID first.** `TransferService` always locks the lower wallet ID first, whichever direction the money moves. Every transaction takes locks in the same global order, so two transfers between the same wallets queue instead of deadlocking. `TransferServiceTest::test_wallets_are_locked_lowest_id_first_whichever_way_money_moves` records the locking queries and asserts the order.
3. **Retry deadlocks a fixed number of times.** Other paths (deposits, withdrawals) may still meet transfers in a different order. Laravel's `transaction($callback, attempts: 3)` rolls back and retries on MySQL deadlock (1213) and lock wait timeout. The retry re-reads everything under fresh locks, so it is safe. After three attempts the error goes to the caller.
4. **Redis submit lock in front.** The `SubmitLock` middleware takes a short Redis lock per user (`SET NX` with a TTL) for the length of a money-moving request. A double click gets `429` before it opens a transaction or waits on a row lock. This only reduces load. Correctness never depends on it: if Redis is lost, the row locks still prevent the double spend.
5. **Database backstops.** `wallets.balance` is `UNSIGNED`, so even a bug that skipped the funds check could not store a negative balance. The ledger's unique key stops a posting from being applied twice (ADR 0002).

## Consequences

Good:

- No double spends and no lost updates, however requests interleave. Step 5 adds a test that runs 50 real parallel processes against one wallet to prove it.
- Deadlocks between transfers are designed out, not just retried.

Costs:

- Requests against the same wallet run one at a time. A single very hot wallet (a merchant or house account) becomes a bottleneck. The next step there is to split it into several sub-accounts, or to credit it asynchronously from a queue.
- Locks are held for the whole transaction, so nothing slow (HTTP calls, heavy queries) belongs inside it. Withdrawals break this rule on purpose. ADR 0005 explains why, and the outbox alternative.
- The submit lock is per user, not per endpoint: a user cannot run a transfer and a withdrawal in parallel. That is acceptable for a wallet and simpler to reason about.
