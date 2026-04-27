<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Crypto;

/**
 * Outcomes from Signer::verifyRequest. String values rather than enum
 * for PHP 7.4 compatibility (enums require 8.1).
 *
 * Use the constants — never compare against the literal strings — so a
 * future refactor can change them without breaking call sites.
 */
final class VerificationResult
{
    public const VALID = 'valid';
    public const INVALID_SIGNATURE = 'invalid_signature';
    public const EXPIRED_TIMESTAMP = 'expired_timestamp';
    public const REPLAYED_NONCE = 'replayed_nonce';
    public const MISSING_HEADERS = 'missing_headers';

    private function __construct() {}  // never instantiated
}
