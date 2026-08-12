<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

final readonly class DreamOutcome
{
    /**
     * @param list<string> $failedGates
     * @param string       $spaceId
     * @param string       $dreamDate
     * @param string       $status
     * @param string       $baselineReleaseId
     * @param ?string      $candidateReleaseId
     * @param ?string      $evaluationDigest
     */
    public function __construct(
        public string $spaceId,
        public string $dreamDate,
        public string $status,
        public string $baselineReleaseId,
        public ?string $candidateReleaseId = null,
        public ?string $evaluationDigest = null,
        public array $failedGates = [],
    ) {}
}
