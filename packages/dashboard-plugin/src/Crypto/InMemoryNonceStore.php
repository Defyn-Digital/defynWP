<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Crypto;

/**
 * Process-local nonce store. Used by tests and any code path that doesn't
 * need cross-request replay protection.
 *
 * NOT suitable for production REST handling — those need WP-transient or
 * Redis backing so two PHP processes don't accept the same nonce twice.
 * F4 introduces a transient-backed implementation.
 */
final class InMemoryNonceStore implements NonceStore
{
    /** @var array<string, int>  nonce => unix-timestamp-when-it-expires */
    private $seen = [];

    public function remember(string $nonce, int $ttlSeconds): bool
    {
        $now = time();
        // Sweep expired entries lazily; cheap when the store is small.
        foreach ($this->seen as $existingNonce => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->seen[$existingNonce]);
            }
        }

        if (isset($this->seen[$nonce])) {
            return false;  // replay
        }

        $this->seen[$nonce] = $now + $ttlSeconds;
        return true;
    }
}
