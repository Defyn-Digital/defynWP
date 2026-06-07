<?php

declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

final class NoCoreUpdateAvailableException extends CoreUpgradeException
{
    public function __construct(string $message = 'No core update available.')
    {
        parent::__construct($message);
    }
}
