<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

use InvalidArgumentException;

final readonly class DreamCandidate
{
    /**
     * @param array<string, mixed> $releasePatch
     * @param array<string, mixed> $capabilityDiff
     * @param string               $proposalId
     * @param string               $spaceId
     * @param string               $baselineReleaseId
     * @param int                  $baselineMemoryRevision
     * @param string               $candidateReleaseId
     * @param string               $candidateDigest
     * @param string               $hypothesis
     * @param string               $riskClass
     */
    public function __construct(
        public string $proposalId,
        public string $spaceId,
        public string $baselineReleaseId,
        public int $baselineMemoryRevision,
        public string $candidateReleaseId,
        public string $candidateDigest,
        public array $releasePatch,
        public array $capabilityDiff,
        public string $hypothesis,
        public string $riskClass,
    ) {
        if ($this->baselineMemoryRevision < 0) {
            throw new InvalidArgumentException('A Dream candidate requires a valid baseline memory revision.');
        }
    }
}
