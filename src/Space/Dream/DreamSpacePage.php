<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

final readonly class DreamSpacePage
{
    /**
     * @param list<string> $spaceIds
     * @param ?string      $nextCursor
     */
    public function __construct(
        public array $spaceIds,
        public ?string $nextCursor,
    ) {}
}
