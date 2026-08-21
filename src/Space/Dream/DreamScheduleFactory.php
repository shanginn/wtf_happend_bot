<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

use Bot\Config\TemporalExecutionIdentity;
use Carbon\CarbonInterval;
use InvalidArgumentException;
use Temporal\Client\Schedule\Action\StartWorkflowAction;
use Temporal\Client\Schedule\Policy\ScheduleOverlapPolicy;
use Temporal\Client\Schedule\Policy\SchedulePolicies;
use Temporal\Client\Schedule\Schedule;
use Temporal\Client\Schedule\Spec\ScheduleSpec;
use Temporal\Client\Schedule\Spec\ScheduleState;
use Temporal\Common\IdReusePolicy;

final class DreamScheduleFactory
{
    public const string SCHEDULE_ID = 'space-nightly-dream-v1';

    public static function nightly(
        string $taskQueue,
        string $hostReleaseId,
        bool $enabled = true,
        string $timeZone = 'Asia/Yekaterinburg',
        int $hour = 3,
        int $minute = 17,
        int $jitterMinutes = 30,
    ): Schedule {
        if ($taskQueue === '') {
            throw new InvalidArgumentException('Dream task queue must not be empty.');
        }
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $jitterMinutes < 0) {
            throw new InvalidArgumentException('Dream schedule time is invalid.');
        }

        $cron = sprintf('%d %d * * *', $minute, $hour);
        $spec = ScheduleSpec::new()
            ->withAddedCronString($cron)
            ->withTimezoneName($timeZone)
            ->withJitter(CarbonInterval::minutes($jitterMinutes));

        $action = StartWorkflowAction::new(DreamCoordinatorWorkflowInterface::TYPE)
            ->withWorkflowId(TemporalExecutionIdentity::dreamCoordinatorWorkflowId($hostReleaseId))
            ->withTaskQueue($taskQueue)
            ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicate)
            ->withWorkflowTaskTimeout(CarbonInterval::minute())
            ->withInput([new DreamCoordinatorInput(
                timeZone: $timeZone,
                hostReleaseId: $hostReleaseId,
            )]);

        $policies = SchedulePolicies::new()
            ->withOverlapPolicy(ScheduleOverlapPolicy::Skip)
            ->withCatchupWindow(CarbonInterval::hours(2));

        return Schedule::new()
            ->withAction($action)
            ->withSpec($spec)
            ->withPolicies($policies)
            ->withState(ScheduleState::new()
                ->withPaused(!$enabled)
                ->withNotes($enabled
                    ? 'Dream v2 is enabled by the active host release.'
                    : 'Dream v2 remains disabled until its offline evaluation gate is qualified.'));
    }
}
