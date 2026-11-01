<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use InvalidArgumentException;

final class GatewayRegistry
{
    /** @var array<string, FakeGateway> */
    private array $gateways = [];

    public function __construct(FakeCryptoGateway $crypto, FakeFiatGateway $fiat)
    {
        foreach ([$crypto, $fiat] as $gateway) {
            $this->gateways[$gateway->name()] = $gateway;
        }
    }

    public function get(string $name): FakeGateway
    {
        return $this->gateways[$name] ?? throw new InvalidArgumentException("Unknown gateway \"{$name}\".");
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->gateways);
    }

    /** @return list<FakeGateway> */
    public function all(): array
    {
        return array_values($this->gateways);
    }
}
