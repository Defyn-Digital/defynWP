<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Auth;

/** Find-or-create a WordPress user from verified Google claims. */
final class UserProvisioner
{
    /**
     * @param array{email:string,name?:string,sub?:string} $claims
     * @return int WP user id
     */
    public function findOrCreate(array $claims): int
    {
        $email = (string) $claims['email'];
        $existing = get_user_by('email', $email);
        if ($existing instanceof \WP_User) {
            $userId = (int) $existing->ID;
        } else {
            $userId = wp_insert_user([
                'user_login'   => $email,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password(64, true, true),
                'display_name' => (string) ($claims['name'] ?? $email),
                'role'         => 'subscriber',
            ]);
            if (is_wp_error($userId) || (int) $userId <= 0) {
                // wp_insert_user can fail if user_login collides; fall back to email lookup
                $byEmail = get_user_by('email', $email);
                $userId = $byEmail instanceof \WP_User ? (int) $byEmail->ID : 0;
            }
            $userId = (int) $userId;
        }
        if ($userId > 0 && isset($claims['sub'])) {
            update_user_meta($userId, 'defyn_google_sub', (string) $claims['sub']);
        }
        return $userId;
    }
}
