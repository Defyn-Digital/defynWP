<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * Validates URLs submitted to POST /sites.
 *
 * Pure value-returning — returns ValidationResult instead of throwing,
 * so the controller can branch into the spec § 9.1 envelope without
 * exception-handling ceremony.
 */
final class UrlValidator
{
    /**
     * @param (\Closure(string): array<int, array<string, mixed>>)|null $dnsResolver
     *   Optional resolver injected for tests. When null, a real DNS lookup
     *   is performed with dns_get_record() against DNS_A | DNS_AAAA so that
     *   IPv4-only, IPv6-only, and dual-stack hosts all resolve.
     */
    public function __construct(
        private readonly bool $checkDns = true,
        private readonly ?\Closure $dnsResolver = null,
    ) {}

    public function validate(string $url): ValidationResult
    {
        if ($url === '') {
            return ValidationResult::invalid('sites.invalid_url', 'URL is required.');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ValidationResult::invalid('sites.invalid_url', 'URL is not well-formed.');
        }

        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            return ValidationResult::invalid('sites.invalid_url', 'URL must use HTTPS.');
        }
        if (empty($parts['host'])) {
            return ValidationResult::invalid('sites.invalid_url', 'URL must include a host.');
        }

        if ($this->checkDns) {
            $resolver = $this->dnsResolver ?? static fn(string $host): array =>
                (array) (dns_get_record($host, DNS_A | DNS_AAAA) ?: []);
            $records = $resolver($parts['host']);
            if (empty($records)) {
                return ValidationResult::invalid('sites.invalid_url', 'URL host does not resolve in DNS.');
            }
        }

        return ValidationResult::valid();
    }
}

final class ValidationResult
{
    private function __construct(
        public readonly bool    $isValid,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function valid(): self
    {
        return new self(true);
    }

    public static function invalid(string $code, string $message): self
    {
        return new self(false, $code, $message);
    }
}
