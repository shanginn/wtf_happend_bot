<?php

declare(strict_types=1);

namespace Bot\Space\Sandbox;

interface CapsuleRegistryInterface
{
    public function stage(CapsuleStageRequest $request): CapsuleStageResult;
}
