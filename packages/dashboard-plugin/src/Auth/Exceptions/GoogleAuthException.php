<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Auth\Exceptions;

use RuntimeException;

/** Thrown by GoogleIdTokenVerifier. `errorCode` is the REST error code; `status` the HTTP status. */
final class GoogleAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message
    ) {
        parent::__construct($message);
    }
}
