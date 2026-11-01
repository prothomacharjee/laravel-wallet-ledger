<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Deposit\Deposit;
use App\Domain\Deposit\DepositService;
use App\Domain\Gateway\FakeGateway;
use App\Domain\Gateway\GatewayRegistry;
use App\Domain\Gateway\SignatureVerifier;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

trait MakesDeposits
{
    protected function pendingDeposit(User $user, string $amount = '25.00', Currency $currency = Currency::USD, string $gateway = 'fake-fiat'): Deposit
    {
        return $this->app->make(DepositService::class)->create($user, $gateway, Money::of($amount, $currency));
    }

    protected function gateway(string $name = 'fake-fiat'): FakeGateway
    {
        return $this->app->make(GatewayRegistry::class)->get($name);
    }

    /** @return TestResponse<Response> */
    protected function sendWebhook(string $gateway, string $body, ?string $signature = null): TestResponse
    {
        $signature ??= SignatureVerifier::sign($body, $this->gateway($gateway)->webhookSecret());

        return $this->call('POST', "/api/webhooks/{$gateway}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature,
        ], $body);
    }
}
