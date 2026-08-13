<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

use Bot\Config\TemporalExecutionIdentity;
use Carbon\CarbonInterval;
use DateTimeZone;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\IdReusePolicy;
use Temporal\Common\RetryOptions;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Temporal\Workflow\ChildWorkflowOptions;
use Throwable;

final class DreamCoordinatorWorkflow implements DreamCoordinatorWorkflowInterface
{
    private ActivityProxy|DreamActivitiesInterface $activities;

    public function __construct()
    {
        /** @var DreamActivitiesInterface $activities */
        $activities = Workflow::newActivityStub(
            DreamActivitiesInterface::class,
            ActivityOptions::new()
                ->withScheduleToCloseTimeout(CarbonInterval::minutes(5))
                ->withStartToCloseTimeout(CarbonInterval::minute())
                ->withRetryOptions(
                    RetryOptions::new()
                        ->withInitialInterval(1)
                        ->withMaximumInterval(30)
                        ->withMaximumAttempts(5),
                ),
        );
        $this->activities = $activities;
    }

    public function run(DreamCoordinatorInput $input): array
    {
        $dreamDate = $input->dreamDate ?? Workflow::now()
            ->setTimezone(new DateTimeZone($input->timeZone))
            ->format('Y-m-d');
        $attempted = 0;
        $completed = 0;
        $failed    = 0;
        $cursor    = null;

        do {
            $page = $this->activities->listEligibleSpaces(
                dreamDate: $dreamDate,
                policy: $input->policy,
                limit: $input->batchSize,
                cursor: $cursor,
            );

            foreach (array_chunk($page->spaceIds, $input->maximumConcurrentDreams) as $spaceIds) {
                $scopes = [];
                foreach ($spaceIds as $spaceId) {
                    ++$attempted;
                    $scopes[] = Workflow::async(function () use ($dreamDate, $input, $spaceId): DreamOutcome {
                        /** @var SpaceDreamWorkflowInterface $dream */
                        $dream = Workflow::newChildWorkflowStub(
                            SpaceDreamWorkflowInterface::class,
                            ChildWorkflowOptions::new()
                                ->withWorkflowId(TemporalExecutionIdentity::spaceDreamWorkflowId(
                                    $spaceId,
                                    $dreamDate,
                                    $input->hostReleaseId,
                                ))
                                ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicateFailedOnly)
                                ->withRetryOptions(
                                    RetryOptions::new()
                                        ->withInitialInterval(30)
                                        ->withMaximumInterval(300)
                                        ->withMaximumAttempts(3),
                                )
                                ->withWorkflowTaskTimeout(CarbonInterval::minute()),
                        );

                        return $dream->run(new SpaceDreamInput(
                            spaceId: $spaceId,
                            dreamDate: $dreamDate,
                            policy: $input->policy,
                        ));
                    });
                }

                foreach ($scopes as $scope) {
                    try {
                        $scope->await();
                        ++$completed;
                    } catch (Throwable) {
                        ++$failed;
                    }
                }
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return [
            'dreamDate' => $dreamDate,
            'attempted' => $attempted,
            'completed' => $completed,
            'failed'    => $failed,
        ];
    }
}
