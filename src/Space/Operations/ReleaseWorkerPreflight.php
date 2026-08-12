<?php

declare(strict_types=1);

namespace Bot\Space\Operations;

use Carbon\CarbonInterval;
use InvalidArgumentException;
use RuntimeException;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;

final readonly class ReleaseWorkerPreflight
{
    private const int WORKFLOW_TIMEOUT_SECONDS = 30;
    private const int RESULT_TIMEOUT_SECONDS   = 45;

    public function __construct(
        private WorkflowClientInterface $client,
    ) {}

    /**
     * @param string $releaseId
     * @param string $attemptId
     * @param string $agentTaskQueue
     * @param string $dreamTaskQueue
     *
     * @return list<array{lane: string, taskQueue: string, workflowId: string, runId: ?string}>
     */
    public function run(
        string $releaseId,
        string $attemptId,
        string $agentTaskQueue,
        string $dreamTaskQueue,
    ): array {
        self::assertIdentifier($releaseId, 'releaseId');
        self::assertIdentifier($attemptId, 'attemptId');
        self::assertTaskQueue($agentTaskQueue, 'agentTaskQueue');
        self::assertTaskQueue($dreamTaskQueue, 'dreamTaskQueue');

        return [
            $this->check(
                lane: 'agent',
                workflowClass: AgentWorkerHealthWorkflowInterface::class,
                responsePrefix: AgentWorkerHealthWorkflowInterface::RESPONSE_PREFIX,
                taskQueue: $agentTaskQueue,
                releaseId: $releaseId,
                attemptId: $attemptId,
            ),
            $this->check(
                lane: 'dream',
                workflowClass: DreamWorkerHealthWorkflowInterface::class,
                responsePrefix: DreamWorkerHealthWorkflowInterface::RESPONSE_PREFIX,
                taskQueue: $dreamTaskQueue,
                releaseId: $releaseId,
                attemptId: $attemptId,
            ),
        ];
    }

    private static function assertIdentifier(string $value, string $field): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("{$field} is not a valid release identifier.");
        }
    }

    private static function assertTaskQueue(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("{$field} cannot be empty.");
        }
    }

    /**
     * @param class-string $workflowClass
     * @param string       $lane
     * @param string       $responsePrefix
     * @param string       $taskQueue
     * @param string       $releaseId
     * @param string       $attemptId
     *
     * @return array{lane: string, taskQueue: string, workflowId: string, runId: ?string}
     */
    private function check(
        string $lane,
        string $workflowClass,
        string $responsePrefix,
        string $taskQueue,
        string $releaseId,
        string $attemptId,
    ): array {
        $workflowId = sprintf('release-preflight/%s/%s/%s', $releaseId, $lane, $attemptId);
        $workflow   = $this->client->newWorkflowStub(
            $workflowClass,
            WorkflowOptions::new()
                ->withWorkflowId($workflowId)
                ->withTaskQueue($taskQueue)
                ->withWorkflowExecutionTimeout(CarbonInterval::seconds(self::WORKFLOW_TIMEOUT_SECONDS))
                ->withWorkflowRunTimeout(CarbonInterval::seconds(self::WORKFLOW_TIMEOUT_SECONDS))
                ->withWorkflowTaskTimeout(CarbonInterval::seconds(10))
                ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicate),
        );
        $run      = $this->client->start($workflow, $releaseId, $attemptId);
        $expected = implode(':', [$responsePrefix, $releaseId, $attemptId]);
        $actual   = $run->getResult('string', self::RESULT_TIMEOUT_SECONDS);
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new RuntimeException(sprintf(
                'Temporal %s worker preflight returned an invalid response.',
                $lane,
            ));
        }

        return [
            'lane'       => $lane,
            'taskQueue'  => $taskQueue,
            'workflowId' => $workflowId,
            'runId'      => $run->getExecution()->getRunID(),
        ];
    }
}
