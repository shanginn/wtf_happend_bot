<?php

declare(strict_types=1);

namespace Bot\Space\Command;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface]
interface SpaceCommandActivityInterface
{
    public const string EXECUTE = 'SpaceCommand.execute';

    #[ActivityMethod(name: self::EXECUTE)]
    public function execute(SpaceCommandExecutionInput $input): string;
}
