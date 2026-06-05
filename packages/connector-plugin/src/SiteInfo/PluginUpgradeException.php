<?php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

/**
 * Base class for every failure mode the plugin upgrader can surface.
 *
 * Task 3 (the REST controller) will catch this single type and map each
 * concrete subclass to a spec-defined error code/HTTP status:
 *   - UnknownSlugException        → 404 plugins.unknown_slug
 *   - NoUpdateAvailableException  → 409 plugins.no_update_available
 *   - UpgradeFailedException      → 502 plugins.update_failed
 */
abstract class PluginUpgradeException extends \RuntimeException
{
}
