<?php

declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

final class ThemeUpgradeFailedException extends ThemeUpgradeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
