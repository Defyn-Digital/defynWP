<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Crypto;

/**
 * Store of recently-seen nonces, used for replay protection in Signer::verifyRequest.
 *
 * F2 ships InMemoryNonceStore (good for tests + dev). F4+ will add a
 * WP-transient-backed implementation when REST controllers wire signed
 * requests into the plugin.
 */
interface NonceStore
{
    /**
     * Atomically check-and-store a nonce.
     *
     * @return bool true if the nonce was newly stored (not a replay), false if
     *              it was already present (i.e. a replay attempt).
     */
    public function remember(string $nonce, int $ttlSeconds): bool;
}
