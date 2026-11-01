# ADR 0005: Withdrawals: gateway call inside the transaction vs. an outbox

Status: accepted. Both versions are in the repo. `WithdrawalService` mirrors what runs in production; `OutboxWithdrawalService` is added here.

## Context

A withdrawal changes two systems: our database (debit the wallet) and the payment provider (send the money). There is no transaction that spans both. Whatever order we pick, a crash between the two steps leaves them disagreeing:

- Gateway first, then debit: the payout is sent, the debit fails, the user keeps the money.
- Debit first, then gateway, in separate steps: the debit commits, the call never happens, the user's money is stuck.

## Decision

### In-transaction (`WithdrawalService`, `POST /api/withdrawals`)

Lock the wallet, check limits, debit, write the ledger pair, then call the gateway as the **last** step **inside** the transaction:

- Gateway refuses: the exception rolls back the debit. Nothing happened.
- Gateway accepts, then our commit fails: we call `cancelWithdrawal` upstream and record the attempt in `withdrawal_cancellations`, outside the rolled-back transaction.
- A deadlock retry sends the same reference, which the gateway treats as the same payout.

It is simple and the user gets a final answer in one request. `WithdrawalServiceTest` covers both failure paths.

### Outbox (`OutboxWithdrawalService`, `POST /api/withdrawals/queued`)

1. One transaction: lock, check limits, debit, write the ledger pair, insert a row into `withdrawal_outbox`. Respond `202` with a `pending` withdrawal.
2. After commit, a `SendWithdrawal` job calls the gateway with the withdrawal's reference.
3. Accepted: mark the withdrawal `completed` under a row lock. Refused: refund the wallet, write a reversing ledger entry (`withdrawal-reversal`), mark it `failed`. Outage: count the attempt and let the queue retry.
4. `withdrawals:relay` runs every minute and re-queues any message still unsent after 60 seconds, which covers a job that was never queued or ran out of retries.

Delivery is at least once. That is safe because the gateway dedupes by our reference and the result is applied only while the withdrawal is still `pending`. `OutboxWithdrawalServiceTest` covers payout, refusal, a message sent twice, an outage and the relay.

## Consequences

| | In-transaction | Outbox |
|---|---|---|
| Wallet row locked during | the gateway's HTTP round trip | local writes only |
| Gateway slow or down | requests pile up on the lock; throughput drops for that wallet | payouts wait in the outbox; requests stay fast |
| Commit fails after gateway accepted | needs an upstream cancel, which can itself fail and needs manual follow-up | cannot happen: the gateway is called only after commit |
| Answer to the user | final, in the same request | `pending`, final later |
| Moving parts | one service | service, job, relay, worker |

The outbox is the better long-term design. Holding a row lock across a network call couples our throughput to the provider's latency, and the cancel path is a second distributed operation with its own failure modes. The outbox replaces both problems with retries of one idempotent call.

Costs of the outbox: the user sees `pending` and needs a status endpoint or notification; a payout can wait until the relay runs; operators must watch for messages with many `attempts`. The in-transaction version stays for comparison, and because it is what the production system runs today.
