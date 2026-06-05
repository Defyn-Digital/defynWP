<?php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

/**
 * Thrown when the slug resolves to an installed plugin but WordPress has no
 * pending update for it in the `update_plugins` site transient.
 *
 * We do not implicitly trigger a fresh update check here — the caller (or
 * the SyncPluginsService background sync) is responsible for keeping that
 * transient current. Treating "no update known" as a hard 409 avoids the
 * race where the dashboard requests an update we'd just downgrade to.
 */
final class NoUpdateAvailableException extends PluginUpgradeException
{
    public function __construct(string $slug)
    {
        parent::__construct($slug);
    }
}
