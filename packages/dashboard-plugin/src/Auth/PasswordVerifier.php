<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Auth;

use Defyn\Dashboard\Auth\Exceptions\InvalidCredentialsException;

/**
 * Validates email-or-login + password against WP's user table.
 *
 * Wraps wp_authenticate so REST controllers can be tested via this seam
 * without going through WP's full authentication filter chain. Future-me
 * can add MFA here without touching every controller.
 */
final class PasswordVerifier
{
    /**
     * @return int the user_id on success
     * @throws InvalidCredentialsException on any failure (unknown user, wrong password, empty creds)
     */
    public function verify(string $emailOrLogin, string $password): int
    {
        if ($emailOrLogin === '' || $password === '') {
            throw new InvalidCredentialsException('Invalid credentials');
        }

        $user = wp_authenticate($emailOrLogin, $password);

        if (is_wp_error($user) || !($user instanceof \WP_User) || $user->ID === 0) {
            throw new InvalidCredentialsException('Invalid credentials');
        }

        return (int) $user->ID;
    }
}
