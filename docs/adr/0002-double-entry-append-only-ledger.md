# ADR 0002: Double-entry, append-only transaction ledger

Status: accepted

## Context

A wallet balance column on its own answers "how much?" but not "why?". When a balance looks wrong, there is nothing to check it against. Support, audits and reconciliation with payment providers all need the history behind every number, and that history must not be editable after the fact.

## Decision

Every money movement is recorded in a double-entry transaction ledger:

- `ledger_accounts`: one row per place money can sit. A customer wallet has its own account. Money entering or leaving through a payment gateway passes through a clearing account for that gateway and currency.
- `ledger_entries`: one row per side of a movement. `LedgerWriter::post()` always writes **a debit and a credit of the same amount** for the same reference (for example `deposit:42`).

Rules:

1. **Balanced.** Both legs go in one `INSERT` statement, inside the same database transaction as the wallet balance change. The writer refuses to run outside a transaction. If anything fails, the balance change and the entries roll back together.
2. **Append-only.** Entries are never updated or deleted. The `LedgerEntry` model throws on update and delete, and MySQL triggers reject `UPDATE` and `DELETE` on the table, so raw queries that skip the model are refused as well. A mistake is fixed with a new reversing posting, which keeps the error and its fix both visible.
3. **Idempotent.** A unique key on `(reference_type, reference_id, ledger_account_id, direction)` means the same posting cannot be written twice. A retry hits the key and gets `DuplicatePosting`, not a second credit.
4. **Sign convention.** An account's balance is credits minus debits. Customer wallets are positive. Clearing accounts go negative by the amount that has entered the system through them.
5. **Verifiable.** `php artisan ledger:verify` checks that every reference nets to zero per currency, and exits non-zero if not. Step 4 extends it to check every wallet balance against its ledger account.

## Consequences

Good:

- Any balance can be rebuilt from entries, and any entry traces back to the operation that caused it.
- Duplicate deliveries (webhooks, retries, double clicks that get past other guards) are stopped by the database, not by application timing.
- `ledger:verify` turns "the books balance" into a check you can run in CI or on a schedule.

Costs:

- Every movement writes two extra rows. At high volume `ledger_entries` grows fastest of all tables and will need partitioning or archiving by date.
- The wallet balance column is a cached copy of the ledger sum, kept in step by doing both writes in one transaction. `ledger:verify` exists to catch any drift.
- Creating triggers on a MySQL server with binary logging needs the `TRIGGER` privilege (and `SUPER`, or `log_bin_trust_function_creators`, on some setups). Migrations must run as a user that has it.
- This is a transaction ledger, not an accounting system: there is no chart of accounts, no periods and no financial reporting.
