<?php

declare(strict_types=1);

namespace Bot\Space\Persistence;

use InvalidArgumentException;

/**
 * The CAS-visible release and memory pointers used to materialize one runtime batch.
 */
final readonly class SpaceActivationSnapshot
{
    public function __construct(
        public string $spaceId,
        public string $releaseId,
        public int $releaseGeneration,
        public int $memoryRevision,
    ) {
        SpaceId::assert($this->spaceId);

        if ($this->releaseId === '') {
            throw new InvalidArgumentException('An activation snapshot requires an active release.');
        }

        if ($this->releaseGeneration < 1 || $this->memoryRevision < 0) {
            throw new InvalidArgumentException('Activation snapshot revisions are invalid.');
        }
    }
}
