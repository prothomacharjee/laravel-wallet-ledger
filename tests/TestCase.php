<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase rolls back the fake gateways' connection too.
     *
     * @var list<string>
     */
    protected array $connectionsToTransact = ['mysql', 'gateway'];
}
