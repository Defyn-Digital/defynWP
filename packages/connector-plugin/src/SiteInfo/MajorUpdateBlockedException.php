<?php

declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

final class MajorUpdateBlockedException extends CoreUpgradeException
{
    public function __construct(string $current, string $target)
    {
        parent::__construct(sprintf(
            'Major-version updates (%s -> %s) require P2.4.1.',
            $current,
            $target
        ));
    }
}
