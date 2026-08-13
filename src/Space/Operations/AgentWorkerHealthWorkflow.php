<?php

declare(strict_types=1);

namespace Bot\Space\Operations;

use Bot\Space\Runtime\SpaceRuntimeSnapshotLoaderActivityInterface;
use Bot\Space\Runtime\SpaceRuntimeSnapshotRequest;
use Carbon\CarbonInterval;
use RuntimeException;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;

final class AgentWorkerHealthWorkflow implements AgentWorkerHealthWorkflowInterface
{
    private ActivityProxy|SpaceRuntimeSnapshotLoaderActivityInterface $runtimeSnapshotActivity;

    public function __construct()
    {
        /** @var SpaceRuntimeSnapshotLoaderActivityInterface $runtimeSnapshotActivity */
        $runtimeSnapshotActivity = Workflow::newActivityStub(
            SpaceRuntimeSnapshotLoaderActivityInterface::class,
            ActivityOptions::new()
                ->withScheduleToCloseTimeout(CarbonInterval::seconds(20))
                ->withStartToCloseTimeout(CarbonInterval::seconds(10))
                ->withRetryOptions(RetryOptions::new()->withMaximumAttempts(2)),
        );
        $this->runtimeSnapshotActivity = $runtimeSnapshotActivity;
    }

    public function check(string $releaseId, string $attemptId, ?string $spaceId): string
    {
        if ($spaceId !== null) {
            $snapshot = $this->runtimeSnapshotActivity->loadSnapshot(
                new SpaceRuntimeSnapshotRequest(
                    $spaceId,
                    implode(':', ['release-preflight', $releaseId, $attemptId]),
                ),
            );
            if ($snapshot->spaceId !== $spaceId) {
                throw new RuntimeException('Agent worker preflight received a snapshot for another Space.');
            }
        }

        return implode(':', [self::RESPONSE_PREFIX, $releaseId, $attemptId]);
    }
}
