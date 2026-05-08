<?php

declare(strict_types=1);

namespace Defyn\Connector\Storage;

/**
 * Reads and writes the single `wp_options['defyn_connector']` JSON blob.
 *
 * Per spec § 4.2, all connector state lives in one row to avoid
 * autoload bloat from many small options.
 */
final class ConnectorState
{
    public const OPTION_KEY = 'defyn_connector';

    /** @return array<string, mixed> */
    public function all(): array
    {
        $raw = get_option(self::OPTION_KEY, '');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function exists(): bool
    {
        return get_option(self::OPTION_KEY, null) !== null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(array $data): void
    {
        update_option(self::OPTION_KEY, json_encode($data, JSON_THROW_ON_ERROR), false);
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function update(array $patch): void
    {
        $this->save(array_merge($this->all(), $patch));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function reset(): void
    {
        delete_option(self::OPTION_KEY);
    }
}
