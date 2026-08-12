<?php

declare(strict_types=1);

namespace Bot\Space\Runtime;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'SpaceRuntime.')]
interface SpaceRuntimeSnapshotLoaderActivityInterface
{
    #[ActivityMethod]
    public function loadSnapshot(SpaceRuntimeSnapshotRequest $request): SpaceRuntimeSnapshot;
}
