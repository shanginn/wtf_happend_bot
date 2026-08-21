<?php

declare(strict_types=1);

namespace Bot\Space\Workflow;

interface SpaceAgentControlStoreInterface
{
    public function isPaused(string $spaceId): bool;

    public function setPaused(string $spaceId, bool $paused): void;
}
