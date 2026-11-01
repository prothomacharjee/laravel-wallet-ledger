# ADR 0006: Client idempotency keys

Status: accepted. Added in this repo; not part of the production system it mirrors.

## Context

A client sends a transfer, the network drops the response, and the client retries. The server cannot tell a retry from a second, intended transfer of the same amount. Either it applies both (the user paid twice) or it refuses repeats by guessing (the user cannot pay the same person twice in a row).

The submit lock (ADR 0003) only stops requests that overlap in time. A retry that arrives after the first request finished passes it.

## Decision

Money-moving endpoints accept an `Idempotency-Key` header. The `IdempotencyKey` middleware:

1. **Reserves the key before the request runs.** It inserts `(user_id, key, request_hash)` into `idempotency_keys`, where a unique key on `(user_id, key)` lets exactly one request win. The hash covers method, path and raw body.
2. **Stores the response after it runs.** A 2xx or 4xx response is saved against the key, so a business rejection replays as the same rejection. A 5xx deletes the reservation: the operation rolled back, so a retry is safe.
3. **On a repeat:**
   - same key, same body, response stored: the stored response comes back with `Idempotent-Replayed: true`. Nothing runs.
   - same key, different body: `422`. The client has a bug, and guessing which body it meant is worse than refusing.
   - same key, no response stored yet: `409`. The first request is still running, or its process died.

Keys are scoped per user, so two users cannot collide or read each other's responses. The middleware runs after the submit lock, so two requests with the same key never race the reservation.

The header is optional. Without it an endpoint behaves as before.

## Consequences

Good:

- A client can retry any money-moving request without risk of applying it twice. `IdempotencyKeyTest` covers replay, a changed body, an unfinished reservation, and per-user scope.
- The `409` case fails closed: if a process dies after moving money but before storing the response, the retry is refused instead of moving money again.

Costs:

- The response is stored in a separate statement after the money transaction commits. A crash between the two leaves the key stuck on `409`, and the client has to check the result another way (for example by listing transfers). Writing the response in the same transaction as the money movement would close this gap, at the cost of passing the key into every domain service.
- Keys are kept forever. A production system would expire them after a retention window (24 hours is common) with a scheduled prune.
- The hash is over raw bytes, so the same JSON with different key order or whitespace counts as a different body. Retrying clients send identical bytes, so this is stricter than needed, not looser.
