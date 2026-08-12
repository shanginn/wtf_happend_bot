<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

final readonly class DreamEvaluation
{
    /**
     * @param array<string, int|float> $baselineMetrics
     * @param array<string, int|float> $candidateMetrics
     * @param list<string>             $failedGates
     * @param string                   $evaluationId
     * @param string                   $evaluationDigest
     * @param bool                     $passed
     * @param bool                     $sameAuthority
     */
    public function __construct(
        public string $evaluationId,
        public string $evaluationDigest,
        public bool $passed,
        public bool $sameAuthority,
        public array $baselineMetrics,
        public array $candidateMetrics,
        public array $failedGates,
    ) {}
}
