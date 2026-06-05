<?php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

/**
 * Thrown when WordPress's Plugin_Upgrader actually ran but reported failure.
 *
 * The message we carry is the real human-readable error string fished out
 * of either the WP_Error or the CapturingUpgraderSkin's error() channel.
 * Task 3 surfaces it back to the dashboard verbatim inside a 502 envelope.
 */
final class UpgradeFailedException extends PluginUpgradeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
