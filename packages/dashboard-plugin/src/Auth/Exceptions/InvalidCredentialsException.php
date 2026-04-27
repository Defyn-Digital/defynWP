<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown when login credentials don't match a known user. Caller maps to HTTP 401.
 *
 * Note: this exception's message is intentionally generic ("Invalid credentials")
 * — never leak whether the failure was unknown user vs wrong password (timing
 * attacks + enumeration).
 */
final class InvalidCredentialsException extends RuntimeException
{
}
