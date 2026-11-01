<?php

declare(strict_types=1);

namespace App\Domain\Withdrawal;

use App\Domain\Gateway\GatewayRegistry;
use App\Domain\Gateway\PaymentGateway;
use App\Domain\Ledger\LedgerAccount;
use App\Domain\Ledger\LedgerWriter;
use App\Domain\Money\Money;
use App\Domain\Wallet\Wallet;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Debits the wallet and calls the gateway inside one database transaction.
 * A refusal rolls the debit back. If the gateway accepted but our commit
 * then fails, we ask the gateway to cancel and record that we did.
 * ADR 0005 compares this with the outbox version.
 */
final readonly class WithdrawalService
{
    private const ATTEMPTS = 3;

    public function __construct(
        private ConnectionInterface $db,
        private WalletService $wallets,
        private LedgerWriter $ledger,
        private GatewayRegistry $gateways,
        private WithdrawalLimits $limits,
    ) {}

    public function withdraw(User $user, string $gatewayName, Money $amount, string $destination): Withdrawal
    {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A withdrawal amount must be positive.');
        }

        $gateway = $this->gateways->get($gatewayName);
        if (! $gateway->supports($amount->currency)) {
            throw WithdrawalNotAllowed::unsupportedCurrency($gatewayName, $amount->currency);
        }

        $walletId = Wallet::query()->where('user_id', $user->id)->where('currency', $amount->currency)->value('id')
            ?? throw WithdrawalNotAllowed::noWallet($amount->currency);

        // Fixed before the first attempt: a deadlock retry sends the same
        // reference, and the gateway treats it as the same payout.
        $reference = (string) Str::uuid();
        $acceptedAs = null;

        try {
            return $this->db->transaction(function () use ($user, $gateway, $gatewayName, $amount, $destination, $walletId, $reference, &$acceptedAs): Withdrawal {
                $wallet = $this->wallets->lock((int) $walletId);
                $this->limits->check($wallet, $amount);
                $this->wallets->debit($wallet, $amount);

                $withdrawal = new Withdrawal;
                $withdrawal->forceFill([
                    'user_id' => $user->id,
                    'wallet_id' => $wallet->id,
                    'gateway' => $gatewayName,
                    'reference' => $reference,
                    'amount' => $amount->amount,
                    'currency' => $amount->currency,
                    'destination' => $destination,
                    'status' => WithdrawalStatus::Completed,
                ])->save();

                $clearing = LedgerAccount::forCode("gateway:{$gatewayName}:{$amount->currency->value}", $amount->currency);
                $this->ledger->post('withdrawal', (string) $withdrawal->id, $wallet->ledgerAccount, $clearing, $amount);

                // Last, after every local check: a refusal here rolls back all of the above.
                $acceptedAs = $gateway->withdraw($reference, $amount, $destination);
                $withdrawal->forceFill(['provider_transaction_id' => $acceptedAs])->save();

                return $withdrawal;
            }, self::ATTEMPTS);
        } catch (Throwable $e) {
            if ($acceptedAs !== null) {
                $this->cancelUpstream($gateway, $reference, $acceptedAs, $amount, $e);
            }

            throw $e;
        }
    }

    /** Runs after the rollback, so the record survives it. */
    private function cancelUpstream(PaymentGateway $gateway, string $reference, string $transactionId, Money $amount, Throwable $cause): void
    {
        $cancelError = null;
        try {
            $gateway->cancelWithdrawal($transactionId);
        } catch (Throwable $e) {
            $cancelError = $e->getMessage();
        }

        $this->db->table('withdrawal_cancellations')->insert([
            'gateway' => $gateway->name(),
            'reference' => $reference,
            'provider_transaction_id' => $transactionId,
            'amount' => $amount->amount,
            'currency' => $amount->currency->value,
            'reason' => $cause->getMessage(),
            'cancel_accepted' => $cancelError === null,
            'cancel_error' => $cancelError,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
