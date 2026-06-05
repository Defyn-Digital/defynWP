<?php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

/**
 * Thrown when get_plugins() has no entry whose folder matches the requested slug.
 *
 * The slug we put in the message is the unresolvable input; the REST layer
 * (Task 3) surfaces it back to the dashboard inside a 404 envelope so the
 * operator sees which slug WordPress doesn't know about.
 */
final class UnknownSlugException extends PluginUpgradeException
{
    public function __construct(string $slug)
    {
        parent::__construct($slug);
    }
}
