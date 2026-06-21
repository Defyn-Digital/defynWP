<?php
declare(strict_types=1);
namespace Defyn\Connector\Rest;

use Defyn\Connector\Links\BrokenLinkScanner;
use WP_REST_Request;
use WP_REST_Response;

/** POST /defyn-connector/v1/links/scan — signed. Runs a bounded broken-link scan and returns the findings. */
final class LinksScanController
{
    public function __construct(private readonly BrokenLinkScanner $scanner = new BrokenLinkScanner()) {}

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        ob_start();
        try {
            $data                = $this->scanner->scan();
            $data['server_time'] = time();
            return new WP_REST_Response($data, 200);
        } finally {
            ob_end_clean();
        }
    }
}
