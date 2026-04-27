<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Auth;

/**
 * Tracks which refresh-token JTIs are still active per user.
 *
 * Storage: a single `defyn_refresh_jtis` user_meta key per user, holding a JSON
 * array of `[jti, expires_at]` tuples. Expired entries are swept on every read.
 *
 * Why user_meta and not a custom table:
 *   - Foundation is single-tenant — a handful of users with ≤10 devices each.
 *   - No schema migration risk this phase. F4+ may add a custom table if scale demands.
 */
final class RefreshTokenStore
{
    public const META_KEY = 'defyn_refresh_jtis';

    public function remember(int $userId, string $jti, int $expiresAt): void
    {
        $list = $this->loadAndPrune($userId);
        $list[] = ['jti' => $jti, 'expires_at' => $expiresAt];
        update_user_meta($userId, self::META_KEY, $list);
    }

    public function isActive(int $userId, string $jti): bool
    {
        $list = $this->loadAndPrune($userId);
        foreach ($list as $entry) {
            if ($entry['jti'] === $jti) {
                return true;
            }
        }
        return false;
    }

    public function revoke(int $userId, string $jti): void
    {
        $list = $this->loadAndPrune($userId);
        $list = array_values(array_filter($list, static function ($entry) use ($jti) {
            return $entry['jti'] !== $jti;
        }));
        update_user_meta($userId, self::META_KEY, $list);
    }

    /**
     * Read the user's JTI list, drop expired entries, persist the pruned list back.
     *
     * @return array<int, array{jti: string, expires_at: int}>
     */
    private function loadAndPrune(int $userId): array
    {
        $raw = get_user_meta($userId, self::META_KEY, true);
        if (!is_array($raw)) {
            return [];
        }

        $now = time();
        $alive = array_values(array_filter($raw, static function ($entry) use ($now) {
            return is_array($entry)
                && isset($entry['jti'], $entry['expires_at'])
                && (int) $entry['expires_at'] > $now;
        }));

        if (count($alive) !== count($raw)) {
            // Persist pruning so the meta row stays bounded.
            update_user_meta($userId, self::META_KEY, $alive);
        }

        return $alive;
    }
}
