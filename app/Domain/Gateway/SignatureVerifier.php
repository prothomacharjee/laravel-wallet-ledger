<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

final class SignatureVerifier
{
    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /**
     * hash_equals compares in constant time, so response timing does not
     * reveal how many leading characters of a forged signature were right.
     * An empty secret never verifies: anyone could compute that HMAC.
     */
    public static function verify(string $body, ?string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals(self::sign($body, $secret), $signature);
    }
}
