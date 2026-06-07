<?php

declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

final class CoreUpgradeFailedException extends CoreUpgradeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
