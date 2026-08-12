<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

use Bot\Config\TemporalExecutionIdentity;
use LogicException;
use Temporal\Client\GRPC\StatusCode;
use Temporal\Client\Schedule\Action\StartWorkflowAction;
use Temporal\Client\ScheduleClientInterface;
use Temporal\Exception\Client\ServiceClientException;

final readonly class DreamScheduleManager
{
    public function __construct(
        private ScheduleClientInterface $client,
    ) {}

    /**
     * Create or replace the one global nightly coordinator schedule.
     *
     * @param string $taskQueue
     * @param string $timeZone
     * @param int    $hour
     * @param int    $minute
     * @param int    $jitterMinutes
     * @param string $hostReleaseId
     */
    public function install(
        string $taskQueue,
        string $hostReleaseId,
        string $timeZone = 'Asia/Yekaterinburg',
        int $hour = 3,
        int $minute = 17,
        int $jitterMinutes = 30,
    ): void {
        $schedule = DreamScheduleFactory::nightly(
            taskQueue: $taskQueue,
            hostReleaseId: $hostReleaseId,
            timeZone: $timeZone,
            hour: $hour,
            minute: $minute,
            jitterMinutes: $jitterMinutes,
        );

        $handle = $this->client->getHandle(DreamScheduleFactory::SCHEDULE_ID);

        try {
            $description = $handle->describe();
            $handle->update($schedule, $description->conflictToken);
        } catch (ServiceClientException $error) {
            if ($error->getCode() !== StatusCode::NOT_FOUND) {
                throw $error;
            }

            $this->client->createSchedule(
                schedule: $schedule,
                scheduleId: DreamScheduleFactory::SCHEDULE_ID,
            );
        }

        $description = $handle->describe();
        $action      = $description->schedule->action;
        $expectedId  = TemporalExecutionIdentity::dreamCoordinatorWorkflowId($hostReleaseId);
        if (!$action instanceof StartWorkflowAction
            || $action->workflowId !== $expectedId
            || $action->taskQueue->name !== $taskQueue
            || $description->schedule->state->paused
        ) {
            throw new LogicException('Dream schedule did not converge to the current host release.');
        }

        $input = $action->input->getValue(0, DreamCoordinatorInput::class);
        if (!$input instanceof DreamCoordinatorInput || $input->hostReleaseId !== $hostReleaseId) {
            throw new LogicException('Dream schedule input does not identify the current host release.');
        }
    }

    /**
     * Stop the old global schedule at the irreversible cutover boundary.
     * Repeated calls and a missing schedule are both successful convergence.
     *
     * @param string $hostReleaseId
     * @param bool   $delete
     */
    public function quiesce(string $hostReleaseId, bool $delete = false): bool
    {
        TemporalExecutionIdentity::assertReleaseId($hostReleaseId);
        $handle = $this->client->getHandle(DreamScheduleFactory::SCHEDULE_ID);

        try {
            $description = $handle->describe();
            $action      = $description->schedule->action;
            if ($action instanceof StartWorkflowAction
                && TemporalExecutionIdentity::belongsToRelease($action->workflowId, $hostReleaseId)
            ) {
                return false;
            }

            if ($delete) {
                $handle->delete();

                try {
                    $handle->describe();
                } catch (ServiceClientException $error) {
                    if ($error->getCode() === StatusCode::NOT_FOUND) {
                        return true;
                    }

                    throw $error;
                }

                throw new LogicException('Dream schedule deletion was not observable after cutover.');
            } else {
                $handle->pause("Release cutover to {$hostReleaseId}");
                if (!$handle->describe()->schedule->state->paused) {
                    throw new LogicException('Dream schedule did not pause at cutover.');
                }
            }

            return true;
        } catch (ServiceClientException $error) {
            if ($error->getCode() === StatusCode::NOT_FOUND) {
                return false;
            }

            throw $error;
        }
    }
}
