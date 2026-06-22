<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Auth;

/** The single allowed sign-in domain. Pure — no WP. */
final class DomainPolicy
{
    public const ALLOWED = 'defyn.com.au';

    public static function isAllowedEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        return $email !== '' && str_ends_with($email, '@' . self::ALLOWED);
    }

    public static function isAllowedHd(string $hd): bool
    {
        return strtolower(trim($hd)) === self::ALLOWED;
    }
}
