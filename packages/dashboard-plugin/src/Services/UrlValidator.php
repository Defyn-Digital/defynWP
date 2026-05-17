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
    public function __construct(
        private readonly bool $checkDns = true,
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

        if ($this->checkDns && gethostbyname($parts['host']) === $parts['host']) {
            // gethostbyname returns the input unchanged on lookup failure.
            return ValidationResult::invalid('sites.invalid_url', 'URL host does not resolve.');
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
