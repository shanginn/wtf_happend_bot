<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

use Carbon\CarbonInterval;
use LogicException;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Throwable;

final class SpaceDreamWorkflow implements SpaceDreamWorkflowInterface
{
    private ActivityProxy|DreamActivitiesInterface $activities;

    public function __construct()
    {
        /** @var DreamActivitiesInterface $activities */
        $activities = Workflow::newActivityStub(
            DreamActivitiesInterface::class,
            ActivityOptions::new()
                ->withScheduleToCloseTimeout(CarbonInterval::hours(2))
                ->withStartToCloseTimeout(CarbonInterval::minutes(30))
                ->withRetryOptions(
                    RetryOptions::new()
                        ->withInitialInterval(2)
                        ->withMaximumInterval(60)
                        ->withMaximumAttempts(3),
                ),
        );
        $this->activities = $activities;
    }

    public function run(SpaceDreamInput $input): DreamOutcome
    {
        if (!$input->isBound()) {
            $info    = Workflow::getInfo();
            $runId   = $info->execution->getRunID();
            $chainId = $info->firstExecutionRunId;
            if (!is_string($runId) || $runId === '') {
                throw new LogicException('Temporal Dream workflow run ID is unavailable.');
            }
            if (!is_string($chainId) || $chainId === '') {
                $chainId = $runId;
            }
            $input = $input->bindExecution($runId, $chainId, $info->attempt);
        }

        try {
            $input = $this->activities->claimDreamRun($input);

            return $this->runDream($input);
        } catch (Throwable $error) {
            $reason = mb_substr($error::class . ': ' . $error->getMessage(), 0, 2_000);

            try {
                if (!$input->isClaimed()) {
                    throw $error;
                }
                $this->activities->recordFailure(
                    $input,
                    $reason,
                    'sha256:' . hash('sha256', $reason),
                );
            } catch (Throwable) {
                // Preserve the original terminal failure. The recordFailure
                // activity has its own retries and is intentionally best-effort
                // after those retries are exhausted.
            }

            throw $error;
        }
    }

    private function runDream(SpaceDreamInput $input): DreamOutcome
    {
        $evidence   = $this->activities->harvestEvidence($input);
        $regression = $this->activities->reviewActiveRelease($input, $evidence);
        if ($regression->stopsDream()) {
            return new DreamOutcome(
                spaceId: $input->spaceId,
                dreamDate: $input->dreamDate,
                status: $regression->status,
                baselineReleaseId: $regression->fromReleaseId,
                candidateReleaseId: $regression->toReleaseId,
                evaluationDigest: $regression->evaluationDigest,
                failedGates: [$regression->reason],
            );
        }
        if (count($evidence->items) < $input->policy->minimumEvidenceItems) {
            $this->activities->recordNoop($input, $evidence, 'insufficient-evidence');

            return new DreamOutcome(
                spaceId: $input->spaceId,
                dreamDate: $input->dreamDate,
                status: 'no-change',
                baselineReleaseId: $evidence->baselineReleaseId,
            );
        }

        $candidate = $this->activities->buildCandidate($input, $evidence);
        if ($candidate === null) {
            $this->activities->recordNoop($input, $evidence, 'no-improving-hypothesis');

            return new DreamOutcome(
                spaceId: $input->spaceId,
                dreamDate: $input->dreamDate,
                status: 'no-change',
                baselineReleaseId: $evidence->baselineReleaseId,
            );
        }

        $evaluation = $this->activities->evaluateCandidate($input, $evidence, $candidate);
        if (!$evaluation->passed) {
            $this->activities->stageCandidate($input, $candidate, $evaluation);

            return new DreamOutcome(
                spaceId: $input->spaceId,
                dreamDate: $input->dreamDate,
                status: 'rejected',
                baselineReleaseId: $evidence->baselineReleaseId,
                candidateReleaseId: $candidate->candidateReleaseId,
                evaluationDigest: $evaluation->evaluationDigest,
                failedGates: $evaluation->failedGates,
            );
        }

        // No-code Dream is autonomous. The host-owned evaluator already fails
        // closed on authority expansion and high-risk candidates, so a passing
        // candidate is promoted without an administrative queue.
        if (!$evaluation->sameAuthority) {
            $this->activities->stageCandidate($input, $candidate, $evaluation);

            return new DreamOutcome(
                spaceId: $input->spaceId,
                dreamDate: $input->dreamDate,
                status: 'rejected',
                baselineReleaseId: $evidence->baselineReleaseId,
                candidateReleaseId: $candidate->candidateReleaseId,
                evaluationDigest: $evaluation->evaluationDigest,
                failedGates: ['candidate is not eligible for autonomous promotion'],
            );
        }

        $promoted = $this->activities->promoteCandidate($input, $candidate, $evaluation);

        return new DreamOutcome(
            spaceId: $input->spaceId,
            dreamDate: $input->dreamDate,
            status: $promoted ? 'promoted' : 'stale',
            baselineReleaseId: $evidence->baselineReleaseId,
            candidateReleaseId: $candidate->candidateReleaseId,
            evaluationDigest: $evaluation->evaluationDigest,
        );
    }
}
