<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

final readonly class DreamEvidence
{
    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, int|float>   $baselineMetrics
     * @param string                     $spaceId
     * @param string                     $baselineReleaseId
     * @param string                     $baselineReleaseDigest
     * @param string                     $evidenceDigest
     */
    public function __construct(
        public string $spaceId,
        public string $baselineReleaseId,
        public string $baselineReleaseDigest,
        public array $items,
        public array $baselineMetrics,
        public string $evidenceDigest,
    ) {}
}
