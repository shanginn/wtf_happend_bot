<?php

declare(strict_types=1);

namespace Bot\Space\Runtime;

use InvalidArgumentException;

final readonly class SpaceRuntimeSnapshotRequest
{
    public function __construct(
        public string $spaceId,
        public string $batchId,
    ) {
        if (trim($spaceId) === '' || trim($batchId) === '') {
            throw new InvalidArgumentException('Space and batch IDs cannot be empty.');
        }
    }
}
