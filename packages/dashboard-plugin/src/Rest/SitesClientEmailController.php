<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SitesClientEmailController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $body   = $request->get_json_params() ?: [];
        $sites = new SitesRepository();
        if ($sites->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }
        $raw = is_string($body['client_email'] ?? null) ? trim((string) $body['client_email']) : '';
        if ($raw !== '' && !is_email($raw)) {
            return ErrorResponse::create(400, 'sites.invalid_client_email', 'A valid email or empty value is required.');
        }
        $sites->setClientEmail($siteId, $raw === '' ? null : $raw);
        return new WP_REST_Response(['data' => ['client_email' => $raw === '' ? null : $raw], 'error' => null], 200);
    }
}
