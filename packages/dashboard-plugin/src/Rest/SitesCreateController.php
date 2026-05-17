<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Crypto\KeyPair;
use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\UrlValidator;
use WP_REST_Request;
use WP_REST_Response;

final class SitesCreateController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $body   = $request->get_json_params();
        if (!is_array($body)) {
            $body = [];
        }

        $url   = is_string($body['url']   ?? null) ? trim($body['url'])   : '';
        $label = is_string($body['label'] ?? null) ? trim($body['label']) : '';
        $code  = is_string($body['code']  ?? null) ? trim($body['code'])  : '';

        if ($url === '' || $code === '') {
            return ErrorResponse::create(400, 'sites.missing_fields', 'Fields url and code are required.');
        }

        if (strlen($code) !== 12) {
            return ErrorResponse::create(400, 'sites.invalid_code', 'Connection code must be 12 characters.');
        }

        $validator = new UrlValidator(checkDns: !defined('DEFYN_TESTS_RUNNING'));
        $result = $validator->validate($url);
        if (!$result->isValid) {
            return ErrorResponse::create(400, $result->errorCode, $result->errorMessage);
        }

        $repo = new SitesRepository();
        if ($repo->existsForUser($userId, $url)) {
            return ErrorResponse::create(409, 'sites.duplicate_url', 'This URL is already managed.');
        }

        if (!defined('DEFYN_VAULT_KEY') || !is_string(DEFYN_VAULT_KEY) || DEFYN_VAULT_KEY === '') {
            return ErrorResponse::create(500, 'sites.vault_not_configured', 'Vault key is not configured.');
        }

        $pair  = KeyPair::generate();
        $vault = new Vault(DEFYN_VAULT_KEY);
        $encryptedPrivate = $vault->encrypt($pair->privateKey);

        $siteId = $repo->insertPending(
            userId: $userId,
            url:    $url,
            label:  $label,
            ourPublicKey: $pair->publicKey,
            ourPrivateKeyEncrypted: $encryptedPrivate,
        );

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), 'defyn_complete_connection', [$siteId, $code, $url], 'defyn');
        }

        return new WP_REST_Response(['site_id' => $siteId], 202);
    }
}
