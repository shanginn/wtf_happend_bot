<?php

declare(strict_types=1);

namespace Bot\Space\Persistence;

final readonly class SpaceActivationResult
{
    private function __construct(
        public bool $activated,
        public int $releaseGeneration,
    ) {}

    public static function activated(int $releaseGeneration): self
    {
        return new self(true, $releaseGeneration);
    }

    public static function conflict(int $expectedGeneration): self
    {
        return new self(false, $expectedGeneration);
    }
}
