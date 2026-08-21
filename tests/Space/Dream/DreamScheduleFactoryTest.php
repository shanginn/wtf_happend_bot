<?php

declare(strict_types=1);

namespace Tests\Space\Dream;

use Bot\Space\Dream\DreamCoordinatorInput;
use Bot\Space\Dream\DreamCoordinatorWorkflowInterface;
use Bot\Space\Dream\DreamScheduleFactory;
use Carbon\CarbonInterval;
use Temporal\Client\Schedule\Action\StartWorkflowAction;
use Temporal\Client\Schedule\Policy\ScheduleOverlapPolicy;
use Tests\TestCase;

final class DreamScheduleFactoryTest extends TestCase
{
    public function testNightlyScheduleUsesOneNonOverlappingJitteredCoordinator(): void
    {
        $releaseId = str_repeat('a', 64);
        $schedule  = DreamScheduleFactory::nightly(
            taskQueue: 'space-dream',
            hostReleaseId: $releaseId,
            timeZone: 'Asia/Yekaterinburg',
            hour: 3,
            minute: 17,
            jitterMinutes: 30,
        );

        self::assertSame(['17 3 * * *'], $schedule->spec->cronStringList);
        self::assertSame('Asia/Yekaterinburg', $schedule->spec->timezoneName);
        self::assertSame(
            30 * 60,
            (int) CarbonInterval::instance($schedule->spec->jitter)->totalSeconds,
        );
        self::assertSame(ScheduleOverlapPolicy::Skip, $schedule->policies->overlapPolicy);
        self::assertInstanceOf(StartWorkflowAction::class, $schedule->action);
        self::assertSame(DreamCoordinatorWorkflowInterface::TYPE, $schedule->action->workflowType->name);
        self::assertSame(
            "dream-coordinator-v1/release/{$releaseId}",
            $schedule->action->workflowId,
        );
        self::assertSame('space-dream', $schedule->action->taskQueue->name);
        self::assertFalse($schedule->state->paused);

        $input = $schedule->action->input->getValue(0, DreamCoordinatorInput::class);
        self::assertInstanceOf(DreamCoordinatorInput::class, $input);
        self::assertNull($input->dreamDate);
        self::assertSame('Asia/Yekaterinburg', $input->timeZone);
        self::assertSame($releaseId, $input->hostReleaseId);
    }

    public function testDisabledScheduleIsCreatedPausedInsteadOfRacingTheFirstAction(): void
    {
        $schedule = DreamScheduleFactory::nightly(
            taskQueue: 'space-dream',
            hostReleaseId: str_repeat('b', 64),
            enabled: false,
        );

        self::assertTrue($schedule->state->paused);
        self::assertStringContainsString('offline evaluation gate', $schedule->state->notes);
    }
}
