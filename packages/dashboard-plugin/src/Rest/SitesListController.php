<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SitesListController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $sites = (new SitesRepository())->findAllForUser($userId);
        return new WP_REST_Response([
            'sites' => array_map(fn ($s) => $s->toJson(), $sites),
        ], 200);
    }
}
