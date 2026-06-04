<?php

declare(strict_types=1);

namespace Defyn\Connector\Admin;

use Defyn\Connector\Admin\CodeGenerator;
use Defyn\Connector\Storage\ConnectorState;

/**
 * Renders Settings → DefynWP Connector. F4 supports three display states:
 *
 *   state = "unconfigured"        → "Generate Connection Code" form
 *   state = "awaiting-handshake"  → display the code + countdown until expiry
 *   state = "code-consumed"       → "Code consumed. Awaiting dashboard handshake."
 *                                    (F5 will flip to "connected" after handshake.)
 */
final class SettingsPage
{
    public const SLUG               = 'defyn-connector';
    public const ACTION_GENERATE    = 'defyn_connector_generate_code';
    public const ACTION_RESET       = 'defyn_connector_reset';
    public const NONCE_GENERATE     = 'defyn_connector_generate_nonce';
    public const NONCE_RESET        = 'defyn_connector_reset_nonce';

    public function registerMenu(): void
    {
        add_options_page(
            __('DefynWP Connector', 'defyn-connector'),
            __('DefynWP Connector', 'defyn-connector'),
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'defyn-connector'));
        }

        $state    = new ConnectorState();
        $current  = $state->get('state', 'unconfigured');
        $publicKey = (string) $state->get('site_public_key', '');
        $code     = (string) $state->get('connection_code', '');
        $expires  = (int)    $state->get('code_expires_at', 0);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('DefynWP Connector', 'defyn-connector') . '</h1>';

        echo '<h2>' . esc_html__('Site identity', 'defyn-connector') . '</h2>';
        echo '<p><code style="display:inline-block;max-width:100%;word-break:break-all;">' . esc_html($publicKey) . '</code></p>';

        echo '<h2>' . esc_html__('Connection', 'defyn-connector') . '</h2>';

        if ($current === 'awaiting-handshake') {
            $secondsLeft = (int) max(0, $expires - time());
            echo '<p><strong>' . esc_html__('Connection code:', 'defyn-connector') . '</strong></p>';
            echo '<p style="font-size:2rem;font-family:monospace;">' . esc_html($code) . '</p>';
            echo '<p>' . sprintf(
                /* translators: %d: seconds remaining until the code expires */
                esc_html__('Expires in %d seconds. Paste this code into the DefynWP Dashboard "Add Site" form.', 'defyn-connector'),
                (int) $secondsLeft
            ) . '</p>';

            self::renderResetForm();
            echo '</div>';
            return;
        }

        if ($current === 'code-consumed') {
            echo '<p>' . esc_html__('Connection code consumed. Waiting for the dashboard to complete the handshake.', 'defyn-connector') . '</p>';
            self::renderResetForm();
            echo '</div>';
            return;
        }

        if ($current === 'connected') {
            $dashboardPubKey = (string) $state->get('dashboard_public_key', '');
            $connectedAt     = (string) $state->get('connected_at', '');
            $fingerprint     = $dashboardPubKey === '' ? '' : substr($dashboardPubKey, 0, 12) . '…';

            echo '<p>' . esc_html__('This site is connected to a DefynWP dashboard.', 'defyn-connector') . '</p>';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th>' . esc_html__('Dashboard key fingerprint', 'defyn-connector') . '</th>';
            echo '<td><code>' . esc_html($fingerprint) . '</code></td></tr>';
            echo '<tr><th>' . esc_html__('Connected at', 'defyn-connector') . '</th>';
            echo '<td>' . esc_html($connectedAt) . '</td></tr>';
            echo '</tbody></table>';

            // Disconnect form intentionally diverges from renderResetForm():
            // destructive action gets a clearly-different label and a confirm() guard.
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" '
                . 'onsubmit="return confirm(\'' . esc_js(__('Disconnect this site from the dashboard? You will need a new connection code to reconnect.', 'defyn-connector')) . '\');">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_RESET) . '">';
            wp_nonce_field(self::NONCE_RESET);
            echo '<p><button type="submit" class="button button-secondary">' . esc_html__('Disconnect', 'defyn-connector') . '</button></p>';
            echo '</form>';
            echo '</div>';
            return;
        }

        // Default: unconfigured
        echo '<p>' . esc_html__('Generate a one-time connection code, then paste it into the DefynWP Dashboard.', 'defyn-connector') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_GENERATE) . '">';
        wp_nonce_field(self::NONCE_GENERATE);
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Generate Connection Code', 'defyn-connector') . '</button></p>';
        echo '</form>';
        echo '</div>';
    }

    private static function renderResetForm(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_RESET) . '">';
        wp_nonce_field(self::NONCE_RESET);
        echo '<p><button type="submit" class="button">' . esc_html__('Reset / regenerate', 'defyn-connector') . '</button></p>';
        echo '</form>';
    }

    public function handleGenerate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'defyn-connector'));
        }

        check_admin_referer(self::NONCE_GENERATE);

        $generated = CodeGenerator::generate();

        (new ConnectorState())->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => $generated['code'],
            'site_nonce'      => $generated['nonce'],
            'code_created_at' => $generated['created_at'],
            'code_expires_at' => $generated['expires_at'],
        ]);

        // In tests, headers may already be sent, so wrap this check
        if (!headers_sent() && function_exists('wp_safe_redirect')) {
            wp_safe_redirect(admin_url('options-general.php?page=' . self::SLUG));
        }
    }

    public function handleReset(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'defyn-connector'));
        }

        check_admin_referer(self::NONCE_RESET);

        $state = new ConnectorState();
        $existing = $state->all();
        // Preserve keypair + generated_at; drop code-related fields.
        $cleaned = [
            'state'            => 'unconfigured',
            'site_public_key'  => $existing['site_public_key'] ?? '',
            'site_private_key' => $existing['site_private_key'] ?? '',
            'generated_at'     => $existing['generated_at'] ?? gmdate('c'),
        ];
        $state->save($cleaned);

        // In tests, headers may already be sent, so wrap this check
        if (!headers_sent() && function_exists('wp_safe_redirect')) {
            wp_safe_redirect(admin_url('options-general.php?page=' . self::SLUG));
        }
    }
}
