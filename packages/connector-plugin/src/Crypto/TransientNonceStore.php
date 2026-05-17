<?php

declare(strict_types=1);

namespace Defyn\Connector\Crypto;

/**
 * WP transient backed nonce store.
 *
 * Transients survive across PHP-FPM workers and (with an object cache plugin)
 * are O(1) atomic via memcached / redis. Falls back to wp_options when no
 * object cache is configured — fine for the modest volume of signed
 * /status + /heartbeat requests we'll see.
 */
final class TransientNonceStore implements NonceStore
{
    private const PREFIX = 'defyn_conn_nonce_';

    public function remember(string $nonce, int $ttlSeconds): bool
    {
        // Hash user-supplied bytes — never use raw input as a DB key.
        $key = self::PREFIX . md5($nonce);

        if (get_transient($key) !== false) {
            return false;
        }
        set_transient($key, 1, $ttlSeconds);
        return true;
    }
}
