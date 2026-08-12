<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

use InvalidArgumentException;

final readonly class DreamPolicy
{
    public function __construct(
        public int $lookbackHours = 72,
        public int $minimumEvidenceItems = 6,
        public int $maximumEdits = 4,
        public int $maximumInputUpdates = 200,
        public int $minimumReplayCases = 2,
        public int $maximumReplayCases = 6,
        public int $minimumCandidateWinPermille = 600,
        public int $minimumCandidateScoreMargin = 50,
        public int $maximumCandidateRegressionCases = 0,
        public int $minimumRegressionEvidenceItems = 6,
        public int $minimumParentWinPermilleForRollback = 600,
        public int $minimumParentScoreMarginForRollback = 75,
        public bool $autoPromoteSameAuthority = true,
    ) {
        foreach ([
            'lookbackHours'                  => $lookbackHours,
            'minimumEvidenceItems'           => $minimumEvidenceItems,
            'maximumEdits'                   => $maximumEdits,
            'maximumInputUpdates'            => $maximumInputUpdates,
            'minimumReplayCases'             => $minimumReplayCases,
            'maximumReplayCases'             => $maximumReplayCases,
            'minimumRegressionEvidenceItems' => $minimumRegressionEvidenceItems,
        ] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("Dream policy {$name} must be positive.");
            }
        }
        if ($minimumReplayCases > $maximumReplayCases) {
            throw new InvalidArgumentException(
                'Dream policy minimumReplayCases cannot exceed maximumReplayCases.',
            );
        }
        foreach ([
            'minimumCandidateWinPermille'         => $minimumCandidateWinPermille,
            'minimumParentWinPermilleForRollback' => $minimumParentWinPermilleForRollback,
        ] as $name => $value) {
            if ($value < 0 || $value > 1000) {
                throw new InvalidArgumentException("Dream policy {$name} must be between 0 and 1000.");
            }
        }
        foreach ([
            'minimumCandidateScoreMargin'         => $minimumCandidateScoreMargin,
            'minimumParentScoreMarginForRollback' => $minimumParentScoreMarginForRollback,
            'maximumCandidateRegressionCases'     => $maximumCandidateRegressionCases,
        ] as $name => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("Dream policy {$name} cannot be negative.");
            }
        }
        if (!$autoPromoteSameAuthority) {
            throw new InvalidArgumentException(
                'No-code Dream is fully autonomous; passing candidates cannot wait for an administrator.',
            );
        }
    }
}
