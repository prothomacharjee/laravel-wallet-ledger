# ADR 0004: Webhooks: always 202, constant-time signatures, settle by provider ID

Status: accepted

## Context

Deposits are paid at a payment gateway, and the gateway tells us by webhook. Real webhooks are unreliable in every direction:

- The same event is delivered several times (provider retries, timeouts on our side).
- Some events are never delivered at all.
- Anyone on the internet can POST to the webhook URL.
- When our endpoint returns errors, providers retry aggressively and eventually disable the endpoint.

A deposit must still be credited exactly once.

## Decision

1. **Verify every call with HMAC-SHA256** over the raw body and the gateway's shared secret, compared with `hash_equals`. A plain `===` stops at the first differing byte, so response time can leak how much of a forged signature was right. `hash_equals` takes the same time whatever the input. An empty or missing secret never verifies.
2. **Record every call** in `webhook_calls` before doing anything else: payload, whether the signature was valid, and the outcome (`rejected`, `processed`, `duplicate`, `failed`, with the error). Support and reconciliation can always see what the provider sent.
3. **Always answer `202 Accepted`**, whether the call was processed, a duplicate, rejected for its signature, or failed. Error codes would not help the provider fix anything. They would only trigger retry storms, or tell an attacker which forgeries got further. Failures are visible in `webhook_calls` and in logs instead.
4. **Settle through one idempotent path.** `SettlementService::settle()` locks the deposit row, returns early if it is already settled by the same provider transaction, checks the amount and currency match, then credits the wallet (with the balance cap) and writes the ledger pair in one transaction. Three independent guards stop a double credit:
   - the deposit status check under `SELECT ... FOR UPDATE`;
   - a unique key on `deposits.provider_transaction_id`, so one provider payment can settle at most one deposit;
   - the ledger's unique posting key (ADR 0002).
5. **Do not trust webhooks to arrive.** `DepositMatcher` runs every 30 seconds (`deposits:match`, scheduled `withoutOverlapping`). It asks each gateway for payments completed in the last hour and settles any it finds through the same `settle()`. A webhook that arrives after the matcher already settled the deposit is recorded as a duplicate.
6. **One pending deposit per user per payment method.** A stored generated column (`1` while pending, `NULL` otherwise) inside a unique index enforces this in the database, so two concurrent requests cannot both create one.

## Consequences

Good:

- Five deliveries of the same webhook credit once (`WebhookTest::test_same_webhook_delivered_five_times_credits_once`).
- A lost webhook delays a credit by at most about 30 seconds instead of losing it (`DepositMatcherTest`).
- A tampered or mismatched webhook is recorded and never credited.

Costs:

- Because the endpoint always says 202, a broken integration (wrong secret, changed payload) does not show up on the provider's dashboard. Monitoring must alert on the count of `rejected` and `failed` rows in `webhook_calls`.
- The matcher polls every gateway every 30 seconds. At high volume that needs pagination and a stored "last seen" cursor instead of a fixed one-hour lookback.
- A payment that fails settlement for a business reason (for example the balance cap was reached between creation and payment) stays pending, and the matcher retries it every 30 seconds. A real system needs a manual-review state and an alert for this; it is out of scope here.
- Webhooks are processed inside the request. Under heavy load the next step is to store the call and settle it from a queue, keeping the 202 response fast.
