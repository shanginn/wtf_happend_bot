<?php

declare(strict_types=1);

namespace Tests\Space\Operations;

use Bot\Space\Operations\AgentWorkerHealthWorkflowInterface;
use Bot\Space\Operations\DreamWorkerHealthWorkflowInterface;
use Bot\Space\Operations\ReleaseWorkerPreflight;
use Carbon\CarbonInterval;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Mockery;
use RuntimeException;
use stdClass;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Temporal\Workflow\WorkflowExecution;
use Temporal\Workflow\WorkflowRunInterface;
use Tests\TestCase;

final class ReleaseWorkerPreflightTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testBothNewTaskQueuesMustExecuteTheirStableHealthWorkflow(): void
    {
        $client = Mockery::mock(WorkflowClientInterface::class);
        $database = self::databaseWithActiveRootSpace();
        foreach ([
            [
                AgentWorkerHealthWorkflowInterface::class,
                'agent',
                'space-agent-v1',
                AgentWorkerHealthWorkflowInterface::RESPONSE_PREFIX,
                'agent-run',
            ],
            [
                DreamWorkerHealthWorkflowInterface::class,
                'dream',
                'space-dream-v1',
                DreamWorkerHealthWorkflowInterface::RESPONSE_PREFIX,
                'dream-run',
            ],
        ] as [$workflowClass, $lane, $taskQueue, $responsePrefix, $runId]) {
            $stub       = new stdClass();
            $workflowId = "release-preflight/abcdef123456/{$lane}/attempt-01";
            $client
                ->shouldReceive('newWorkflowStub')
                ->once()
                ->withArgs(static function (string $class, WorkflowOptions $options) use (
                    $workflowClass,
                    $workflowId,
                    $taskQueue,
                ): bool {
                    return $class === $workflowClass
                        && $options->workflowId === $workflowId
                        && $options->taskQueue === $taskQueue
                        && (int) CarbonInterval::instance($options->workflowExecutionTimeout)->totalSeconds === 30
                        && (int) CarbonInterval::instance($options->workflowRunTimeout)->totalSeconds === 30
                        && (int) CarbonInterval::instance($options->workflowTaskTimeout)->totalSeconds === 10
                        && $options->workflowIdReusePolicy === IdReusePolicy::AllowDuplicate->value;
                })
                ->andReturn($stub);

            $run = Mockery::mock(WorkflowRunInterface::class);
            $run->shouldReceive('getResult')
                ->once()
                ->with('string', 45)
                ->andReturn("{$responsePrefix}:abcdef123456:attempt-01");
            $run->shouldReceive('getExecution')
                ->once()
                ->andReturn(new WorkflowExecution($workflowId, $runId));
            $startArgs = $lane === 'agent'
                ? [$stub, 'abcdef123456', 'attempt-01', 'spc_runtime_root']
                : [$stub, 'abcdef123456', 'attempt-01'];
            $client->shouldReceive('start')->once()->with(...$startArgs)->andReturn($run);
        }

        $report = (new ReleaseWorkerPreflight($client, $database, 'primary-bot'))->run(
            releaseId: 'abcdef123456',
            attemptId: 'attempt-01',
            agentTaskQueue: 'space-agent-v1',
            dreamTaskQueue: 'space-dream-v1',
        );

        self::assertSame([
            [
                'lane'       => 'agent',
                'taskQueue'  => 'space-agent-v1',
                'workflowId' => 'release-preflight/abcdef123456/agent/attempt-01',
                'runId'      => 'agent-run',
                'runtimeSnapshotChecked' => true,
            ],
            [
                'lane'       => 'dream',
                'taskQueue'  => 'space-dream-v1',
                'workflowId' => 'release-preflight/abcdef123456/dream/attempt-01',
                'runId'      => 'dream-run',
            ],
        ], $report);
    }

    public function testUnexpectedHealthResponseFailsClosed(): void
    {
        $client = Mockery::mock(WorkflowClientInterface::class);
        $stub   = new stdClass();
        $run    = Mockery::mock(WorkflowRunInterface::class);
        $client->shouldReceive('newWorkflowStub')->once()->andReturn($stub);
        $client->shouldReceive('start')->once()->andReturn($run);
        $run->shouldReceive('getResult')->once()->andReturn('stale-or-wrong-worker');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('agent worker preflight returned an invalid response');

        (new ReleaseWorkerPreflight(
            $client,
            self::databaseWithActiveRootSpace(),
            'primary-bot',
        ))->run(
            releaseId: 'abcdef123456',
            attemptId: 'attempt-01',
            agentTaskQueue: 'space-agent-v1',
            dreamTaskQueue: 'space-dream-v1',
        );
    }

    public function testEmptyInstallStillChecksWorkersAndReportsSnapshotWasSkipped(): void
    {
        $client = Mockery::mock(WorkflowClientInterface::class);
        $statement = Mockery::mock(StatementInterface::class);
        $statement->shouldReceive('fetch')->once()->andReturn(false);
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('query')->once()->andReturn($statement);

        foreach ([
            ['agent', AgentWorkerHealthWorkflowInterface::class, 'agent-queue', 'agent-run'],
            ['dream', DreamWorkerHealthWorkflowInterface::class, 'dream-queue', 'dream-run'],
        ] as [$lane, $workflowClass, $queue, $runId]) {
            $stub = new stdClass();
            $client->shouldReceive('newWorkflowStub')
                ->once()
                ->withArgs(static fn (string $class): bool => $class === $workflowClass)
                ->andReturn($stub);
            $run = Mockery::mock(WorkflowRunInterface::class);
            $responsePrefix = $lane === 'agent'
                ? AgentWorkerHealthWorkflowInterface::RESPONSE_PREFIX
                : DreamWorkerHealthWorkflowInterface::RESPONSE_PREFIX;
            $run->shouldReceive('getResult')->once()->andReturn(
                "{$responsePrefix}:abcdef123456:attempt-01",
            );
            $workflowId = "release-preflight/abcdef123456/{$lane}/attempt-01";
            $run->shouldReceive('getExecution')->once()->andReturn(
                new WorkflowExecution($workflowId, $runId),
            );
            $startArgs = $lane === 'agent'
                ? [$stub, 'abcdef123456', 'attempt-01', null]
                : [$stub, 'abcdef123456', 'attempt-01'];
            $client->shouldReceive('start')->once()->with(...$startArgs)->andReturn($run);
        }

        $report = (new ReleaseWorkerPreflight($client, $database, 'primary-bot'))->run(
            releaseId: 'abcdef123456',
            attemptId: 'attempt-01',
            agentTaskQueue: 'agent-queue',
            dreamTaskQueue: 'dream-queue',
        );

        self::assertFalse($report[0]['runtimeSnapshotChecked']);
        self::assertArrayNotHasKey('runtimeSnapshotChecked', $report[1]);
    }

    private static function databaseWithActiveRootSpace(): DatabaseInterface
    {
        $statement = Mockery::mock(StatementInterface::class);
        $statement->shouldReceive('fetch')->once()->andReturn(['id' => 'spc_runtime_root']);
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('query')
            ->once()
            ->withArgs(static fn (string $sql, array $parameters): bool =>
                $parameters === ['primary-bot']
                && str_contains($sql, 'FROM agent_spaces AS space')
                && str_contains($sql, "binding.external_thread_id = ''")
                && str_contains($sql, "binding.platform = 'telegram'")
                && str_contains($sql, 'binding.bot_instance_id = ?')
                && str_contains($sql, "space.status = 'active'")
                && str_contains($sql, 'release.id = space.active_release_id'))
            ->andReturn($statement);

        return $database;
    }
}
