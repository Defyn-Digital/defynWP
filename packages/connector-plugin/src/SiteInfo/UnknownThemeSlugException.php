<?php

declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

final class UnknownThemeSlugException extends ThemeUpgradeException
{
    public function __construct(string $slug)
    {
        parent::__construct($slug);
    }
}
