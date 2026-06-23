<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Auth;

/**
 * Resolves the Google OAuth Client ID: env constant first, then the wp-admin
 * option set via Settings → DefynWP.
 *
 * The env constant (DEFYN_GOOGLE_CLIENT_ID) keeps working where it's set
 * (Bedrock .env, wp-config.php define()). On hosts with no env-var UI
 * (e.g. Kinsta Managed WordPress) the operator pastes the Client ID into the
 * wp-admin field, persisted as the `defyn_google_client_id` option. The Client
 * ID is a non-secret public value (it also ships in the SPA).
 */
final class GoogleConfig
{
    public const OPTION_KEY = 'defyn_google_client_id';

    public static function clientId(): string
    {
        if (defined('DEFYN_GOOGLE_CLIENT_ID') && (string) constant('DEFYN_GOOGLE_CLIENT_ID') !== '') {
            return (string) constant('DEFYN_GOOGLE_CLIENT_ID');
        }
        return (string) get_option(self::OPTION_KEY, '');
    }
}
