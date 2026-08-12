<?php

declare(strict_types=1);

namespace Tests\Space\Operations;

use Bot\Space\Operations\AgentWorkerHealthWorkflowInterface;
use Bot\Space\Operations\DreamWorkerHealthWorkflowInterface;
use Bot\Space\Operations\ReleaseWorkerPreflight;
use Carbon\CarbonInterval;
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
            $client->shouldReceive('start')
                ->once()
                ->with($stub, 'abcdef123456', 'attempt-01')
                ->andReturn($run);
        }

        $report = (new ReleaseWorkerPreflight($client))->run(
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

        (new ReleaseWorkerPreflight($client))->run(
            releaseId: 'abcdef123456',
            attemptId: 'attempt-01',
            agentTaskQueue: 'space-agent-v1',
            dreamTaskQueue: 'space-dream-v1',
        );
    }
}
