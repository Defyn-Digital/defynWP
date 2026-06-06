<?php

declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

final class NoThemeUpdateAvailableException extends ThemeUpgradeException
{
    public function __construct(string $slug)
    {
        parent::__construct($slug);
    }
}
