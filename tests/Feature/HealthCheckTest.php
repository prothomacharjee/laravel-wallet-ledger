<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_application_boots_and_reports_healthy(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_tests_run_against_mysql_not_sqlite(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertNotEmpty(DB::select('SELECT 1 AS ok'));
    }
}
