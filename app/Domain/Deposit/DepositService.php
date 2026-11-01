<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Gateway\GatewayRegistry;
use App\Domain\Money\Money;
use App\Domain\Wallet\BalanceCapExceeded;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final readonly class DepositService
{
    public function __construct(
        private WalletService $wallets,
        private GatewayRegistry $gateways,
    ) {}

    /**
     * Opens a pending deposit and registers it with the gateway. No money
     * moves here; the wallet is credited later by SettlementService.
     */
    public function create(User $user, string $gatewayName, Money $amount): Deposit
    {
        $gateway = $this->gateways->get($gatewayName);
        if (! $gateway->supports($amount->currency)) {
            throw DepositNotAllowed::unsupportedCurrency($gatewayName, $amount->currency);
        }

        $wallet = $this->wallets->open($user, $amount->currency);

        // Early rejection so the customer is never asked to pay money we cannot
        // accept. Settlement checks the cap again under the wallet lock.
        $cap = $this->wallets->maxBalance($amount->currency);
        if ($wallet->balance()->plus($amount)->greaterThan($cap)) {
            throw BalanceCapExceeded::for($wallet, $cap);
        }

        $deposit = new Deposit;
        $deposit->forceFill([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'gateway' => $gatewayName,
            'reference' => (string) Str::uuid(),
            'amount' => $amount->amount,
            'currency' => $amount->currency,
            'status' => DepositStatus::Pending,
        ]);

        try {
            $deposit->save();
        } catch (UniqueConstraintViolationException) {
            throw DepositNotAllowed::pendingExists($gatewayName);
        }

        $gateway->createDeposit($deposit->reference, $amount);

        return $deposit;
    }
}
