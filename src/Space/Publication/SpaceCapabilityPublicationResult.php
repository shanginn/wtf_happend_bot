<?php

declare(strict_types=1);

namespace Bot\Space\Publication;

final readonly class SpaceCapabilityPublicationResult
{
    public function __construct(
        public string $spaceId,
        public string $sourceReleaseId,
        public string $releaseId,
        public int $releaseGeneration,
        public string $kind,
        public string $name,
        public bool $replayed,
    ) {}

    public function message(): string
    {
        return sprintf(
            '%s "%s" %s. The current batch remains pinned; the new release applies from the next batch.',
            ucfirst($this->kind),
            $this->name,
            $this->replayed ? 'was already published' : 'was published',
        );
    }
}
