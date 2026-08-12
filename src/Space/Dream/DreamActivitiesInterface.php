<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface]
interface DreamActivitiesInterface
{
    public const LIST_ELIGIBLE_SPACES  = 'SpaceDream.listEligibleSpaces';
    public const CLAIM_DREAM_RUN       = 'SpaceDream.claimDreamRun';
    public const HARVEST_EVIDENCE      = 'SpaceDream.harvestEvidence';
    public const REVIEW_ACTIVE_RELEASE = 'SpaceDream.reviewActiveRelease';
    public const BUILD_CANDIDATE       = 'SpaceDream.buildCandidate';
    public const EVALUATE_CANDIDATE    = 'SpaceDream.evaluateCandidate';
    public const PROMOTE_CANDIDATE     = 'SpaceDream.promoteCandidate';
    public const STAGE_CANDIDATE       = 'SpaceDream.stageCandidate';
    public const RECORD_NOOP           = 'SpaceDream.recordNoop';
    public const RECORD_FAILURE        = 'SpaceDream.recordFailure';

    #[ActivityMethod(name: self::LIST_ELIGIBLE_SPACES)]
    public function listEligibleSpaces(
        string $dreamDate,
        DreamPolicy $policy,
        int $limit,
        ?string $cursor = null,
    ): DreamSpacePage;

    #[ActivityMethod(name: self::CLAIM_DREAM_RUN)]
    public function claimDreamRun(SpaceDreamInput $input): SpaceDreamInput;

    #[ActivityMethod(name: self::HARVEST_EVIDENCE)]
    public function harvestEvidence(SpaceDreamInput $input): DreamEvidence;

    #[ActivityMethod(name: self::REVIEW_ACTIVE_RELEASE)]
    public function reviewActiveRelease(
        SpaceDreamInput $input,
        DreamEvidence $evidence,
    ): DreamRegressionReview;

    #[ActivityMethod(name: self::BUILD_CANDIDATE)]
    public function buildCandidate(SpaceDreamInput $input, DreamEvidence $evidence): ?DreamCandidate;

    #[ActivityMethod(name: self::EVALUATE_CANDIDATE)]
    public function evaluateCandidate(
        SpaceDreamInput $input,
        DreamEvidence $evidence,
        DreamCandidate $candidate,
    ): DreamEvaluation;

    #[ActivityMethod(name: self::PROMOTE_CANDIDATE)]
    public function promoteCandidate(
        SpaceDreamInput $input,
        DreamCandidate $candidate,
        DreamEvaluation $evaluation,
    ): bool;

    #[ActivityMethod(name: self::STAGE_CANDIDATE)]
    public function stageCandidate(
        SpaceDreamInput $input,
        DreamCandidate $candidate,
        DreamEvaluation $evaluation,
    ): void;

    #[ActivityMethod(name: self::RECORD_NOOP)]
    public function recordNoop(
        SpaceDreamInput $input,
        DreamEvidence $evidence,
        string $reason,
    ): void;

    #[ActivityMethod(name: self::RECORD_FAILURE)]
    public function recordFailure(
        SpaceDreamInput $input,
        string $reason,
        string $failureDigest,
    ): void;
}
