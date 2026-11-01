<?php

declare(strict_types=1);

namespace Tests\Unit\Gateway;

use App\Domain\Gateway\SignatureVerifier;
use PHPUnit\Framework\TestCase;

class SignatureVerifierTest extends TestCase
{
    public function test_valid_signature_verifies(): void
    {
        $this->assertTrue(SignatureVerifier::verify('{"a":1}', SignatureVerifier::sign('{"a":1}', 'secret'), 'secret'));
    }

    public function test_changed_body_wrong_secret_or_missing_signature_fail(): void
    {
        $signature = SignatureVerifier::sign('{"a":1}', 'secret');

        $this->assertFalse(SignatureVerifier::verify('{"a":2}', $signature, 'secret'));
        $this->assertFalse(SignatureVerifier::verify('{"a":1}', $signature, 'other'));
        $this->assertFalse(SignatureVerifier::verify('{"a":1}', null, 'secret'));
        $this->assertFalse(SignatureVerifier::verify('{"a":1}', '', 'secret'));
    }

    public function test_empty_secret_never_verifies_even_with_a_matching_signature(): void
    {
        $this->assertFalse(SignatureVerifier::verify('{"a":1}', SignatureVerifier::sign('{"a":1}', ''), ''));
    }
}
