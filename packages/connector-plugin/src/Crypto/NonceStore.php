<?php

declare(strict_types=1);

namespace Defyn\Connector\Crypto;

/**
 * Replay-protection store for signed-request nonces.
 *
 * Mirrors Defyn\Dashboard\Crypto\NonceStore from F2 (intentional duplication —
 * two-plugin architecture per spec § 8.2). Production implementation is
 * TransientNonceStore (WP transients). Tests can substitute an in-memory
 * stub via the same interface.
 */
interface NonceStore
{
    /**
     * Atomically record the nonce. Returns true if it was new (and stored),
     * false if it had been seen before within the TTL window.
     */
    public function remember(string $nonce, int $ttlSeconds): bool;
}
