<?php

declare(strict_types=1);

namespace Tests\Space\Operations;

use Bot\Space\Operations\AgentWorkerHealthWorkflow;
use Bot\Space\Runtime\SpaceRuntimeSnapshot;
use Bot\Space\Runtime\SpaceRuntimeSnapshotLoaderActivityInterface;
use Bot\Space\Runtime\SpaceRuntimeSnapshotRequest;
use Mockery;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

final class AgentWorkerHealthWorkflowTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testHealthCheckRoundTripsRuntimeSnapshotWithoutReturningItsContent(): void
    {
        $loader = Mockery::mock(SpaceRuntimeSnapshotLoaderActivityInterface::class);
        $loader->shouldReceive('loadSnapshot')
            ->once()
            ->withArgs(static fn (SpaceRuntimeSnapshotRequest $request): bool =>
                $request->spaceId === 'spc_runtime_root'
                && $request->batchId === 'release-preflight:release-123:attempt-456')
            ->andReturn(self::snapshot('spc_runtime_root'));

        $workflow = self::workflowWith($loader);

        self::assertSame(
            'space-agent-worker-ready/v1:release-123:attempt-456',
            $workflow->check('release-123', 'attempt-456', 'spc_runtime_root'),
        );
    }

    public function testHealthCheckRejectsSnapshotForAnotherSpace(): void
    {
        $loader = Mockery::mock(SpaceRuntimeSnapshotLoaderActivityInterface::class);
        $loader->shouldReceive('loadSnapshot')->once()->andReturn(self::snapshot('spc_other_root'));
        $workflow = self::workflowWith($loader);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('snapshot for another Space');

        $workflow->check('release-123', 'attempt-456', 'spc_runtime_root');
    }

    public function testEmptyInstallHealthCheckDoesNotScheduleRuntimeActivity(): void
    {
        $loader = Mockery::mock(SpaceRuntimeSnapshotLoaderActivityInterface::class);
        $loader->shouldNotReceive('loadSnapshot');
        $workflow = self::workflowWith($loader);

        self::assertSame(
            'space-agent-worker-ready/v1:release-123:attempt-456',
            $workflow->check('release-123', 'attempt-456', null),
        );
    }

    private static function workflowWith(
        SpaceRuntimeSnapshotLoaderActivityInterface $loader,
    ): AgentWorkerHealthWorkflow {
        $reflection = new ReflectionClass(AgentWorkerHealthWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('runtimeSnapshotActivity')->setValue($workflow, $loader);

        return $workflow;
    }

    private static function snapshot(string $spaceId): SpaceRuntimeSnapshot
    {
        return new SpaceRuntimeSnapshot(
            snapshotId: 'snp_preflight',
            spaceId: $spaceId,
            releaseId: 'space-release-1',
            releaseDigest: 'sha256:' . str_repeat('a', 64),
            model: 'test/model',
            systemPrompt: 'Private content must not be returned by the health workflow.',
            tools: [],
            memoryRevision: '1',
            capabilityPolicyRevision: 'sha256:' . str_repeat('b', 64),
        );
    }
}
