<?php

declare(strict_types=1);

namespace Bot\Space\Runtime;

use Bot\Telegram\Update;

interface SpaceIdentityResolverInterface
{
    public function resolve(Update $update): SpaceIdentity;
}
