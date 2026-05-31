<?php

declare(strict_types=1);

namespace Defyn\Connector\Crypto;

/**
 * Outcome constants returned by Signer::verifyRequest().
 *
 * Mirror Defyn\Dashboard\Crypto\VerificationResult — kept independent per the
 * two-plugin pattern. Order matters in Signer::verifyRequest (cheap checks
 * reject before expensive ones).
 */
final class VerificationResult
{
    public const VALID              = 'valid';
    public const INVALID_SIGNATURE  = 'invalid_signature';
    public const EXPIRED_TIMESTAMP  = 'expired_timestamp';
    public const REPLAYED_NONCE     = 'replayed_nonce';
    public const MISSING_HEADERS    = 'missing_headers';
}
