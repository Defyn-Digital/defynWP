<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown when a JWT cannot be decoded — invalid signature, malformed structure,
 * expired, or wrong claim type. Caller maps to HTTP 401.
 */
final class InvalidTokenException extends RuntimeException
{
}
