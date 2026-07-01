<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /defyn-connector/v1/upgrade-diagnostics — signed, STRICTLY READ-ONLY.
 *
 * Added in v0.2.5. Reports the host's filesystem + update environment so the
 * dashboard can determine WHY upgrades no-op or 502 on a given host WITHOUT
 * running (and being killed by) a real upgrade. Performs no writes.
 *
 * Motivated by the cuscal.com (WP Engine) investigation: is WP_PLUGIN_DIR
 * actually writable to the PHP user, what filesystem method does the host
 * resolve, and is file modification disabled by a constant? The answer decides
 * between "mimic the standard WP-CLI flow" (writable) and "the host manages
 * updates itself" (locked) — see docs handover.
 *
 * The signature gate runs in VerifySignatureMiddleware::check (registered as
 * permission_callback in RestRouter), so the request is already authentic here.
 */
final class UpgradeDiagnosticsController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // get_filesystem_method() lives in wp-admin/includes/file.php, which is
        // not autoloaded in the REST request context (same gap the upgrader hit).
        if (!function_exists('get_filesystem_method')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $pluginDir  = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '';
        $contentDir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '';
        $upgradeDir = $contentDir !== '' ? $contentDir . '/upgrade' : '';

        $data = [
            'connector_version' => defined('DEFYN_CONNECTOR_VERSION') ? DEFYN_CONNECTOR_VERSION : null,
            'server_time'       => time(),
            'filesystem'        => [
                // $allow_relaxed_file_ownership=false vs true: the upgrader uses
                // the relaxed probe, so a difference here explains a degraded handle.
                'method'                   => function_exists('get_filesystem_method') ? get_filesystem_method([], '', false) : null,
                'method_relaxed_ownership' => function_exists('get_filesystem_method') ? get_filesystem_method([], '', true) : null,
                'plugin_dir'               => $pluginDir,
                'plugin_dir_writable'      => $pluginDir !== '' && is_writable($pluginDir),
                'content_dir_writable'     => $contentDir !== '' && is_writable($contentDir),
                'upgrade_dir'              => $upgradeDir,
                'upgrade_dir_exists'       => $upgradeDir !== '' && is_dir($upgradeDir),
                'upgrade_dir_writable'     => $upgradeDir !== '' && is_dir($upgradeDir) && is_writable($upgradeDir),
            ],
            'constants'         => [
                'FS_METHOD'                  => defined('FS_METHOD') ? FS_METHOD : null,
                'DISALLOW_FILE_MODS'         => defined('DISALLOW_FILE_MODS') ? (bool) DISALLOW_FILE_MODS : false,
                'DISALLOW_FILE_EDIT'         => defined('DISALLOW_FILE_EDIT') ? (bool) DISALLOW_FILE_EDIT : false,
                'AUTOMATIC_UPDATER_DISABLED' => defined('AUTOMATIC_UPDATER_DISABLED') ? (bool) AUTOMATIC_UPDATER_DISABLED : false,
                'WP_AUTO_UPDATE_CORE'        => defined('WP_AUTO_UPDATE_CORE') ? WP_AUTO_UPDATE_CORE : null,
            ],
            'host'              => [
                'is_wpengine'        => function_exists('is_wpe') ? (bool) is_wpe()
                                        : (class_exists('WPE_API') || function_exists('is_wpe_snapshot')),
                'php_version'        => PHP_VERSION,
                'php_uid'            => function_exists('getmyuid') ? getmyuid() : null,
                'plugin_dir_owner'   => $pluginDir !== '' && function_exists('fileowner') ? @fileowner($pluginDir) : null,
                'max_execution_time' => (int) ini_get('max_execution_time'),
                'memory_limit'       => ini_get('memory_limit'),
                'disk_free_bytes'    => $pluginDir !== '' ? @disk_free_space($pluginDir) : null,
            ],
        ];

        return new WP_REST_Response($data, 200);
    }
}
