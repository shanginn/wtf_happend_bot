<?php

declare(strict_types=1);

namespace Tests\Config;

use Bot\Config\TemporalExecutionIdentity;
use InvalidArgumentException;
use Tests\TestCase;

final class TemporalExecutionIdentityTest extends TestCase
{
    public function testQualifiesQueuesAndWorkflowIdsWithOneReleaseIdentity(): void
    {
        $releaseId = str_repeat('a', 64);
        $identity  = new TemporalExecutionIdentity(
            hostReleaseId: $releaseId,
            agentTaskQueue: 'space-agent-v1',
            dreamTaskQueue: "space-dream-v1-{$releaseId}",
        );

        self::assertSame("space-agent-v1-{$releaseId}", $identity->agentTaskQueue);
        self::assertSame("space-dream-v1-{$releaseId}", $identity->dreamTaskQueue);
        self::assertSame(
            "space-agent/spc_123/v1/release/{$releaseId}",
            TemporalExecutionIdentity::spaceAgentWorkflowId('spc_123', $releaseId),
        );
        self::assertSame(
            "dream-coordinator-v1/release/{$releaseId}",
            TemporalExecutionIdentity::dreamCoordinatorWorkflowId($releaseId),
        );
        self::assertSame(
            "space-dream/spc_123/2026-08-12/release/{$releaseId}",
            TemporalExecutionIdentity::spaceDreamWorkflowId('spc_123', '2026-08-12', $releaseId),
        );
        self::assertTrue(TemporalExecutionIdentity::belongsToRelease(
            "space-agent/spc_123/v1/release/{$releaseId}",
            $releaseId,
        ));
        self::assertFalse(TemporalExecutionIdentity::belongsToRelease(
            'space-agent/spc_123/v1/release/old-release',
            $releaseId,
        ));
    }

    public function testRejectsUnsafeOrNonCanonicalReleaseIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TemporalExecutionIdentity(
            hostReleaseId: 'Feature/Unsafe',
            agentTaskQueue: 'space-agent-v1',
            dreamTaskQueue: 'space-dream-v1',
        );
    }
}
