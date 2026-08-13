<?php

declare(strict_types=1);

namespace Bot\Space\Operations;

use Bot\Space\Persistence\SpaceId;
use Carbon\CarbonInterval;
use Cycle\Database\DatabaseInterface;
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
        private DatabaseInterface $database,
        private string $botInstanceId,
    ) {
        if (trim($botInstanceId) === '') {
            throw new InvalidArgumentException('Bot instance ID cannot be empty.');
        }
    }

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

        $runtimeSpaceId = $this->activeRootSpaceId();

        return [
            $this->check(
                lane: 'agent',
                workflowClass: AgentWorkerHealthWorkflowInterface::class,
                responsePrefix: AgentWorkerHealthWorkflowInterface::RESPONSE_PREFIX,
                taskQueue: $agentTaskQueue,
                releaseId: $releaseId,
                attemptId: $attemptId,
                startArgs: [$releaseId, $attemptId, $runtimeSpaceId],
            ),
            $this->check(
                lane: 'dream',
                workflowClass: DreamWorkerHealthWorkflowInterface::class,
                responsePrefix: DreamWorkerHealthWorkflowInterface::RESPONSE_PREFIX,
                taskQueue: $dreamTaskQueue,
                releaseId: $releaseId,
                attemptId: $attemptId,
                startArgs: [$releaseId, $attemptId],
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

    private function activeRootSpaceId(): ?string
    {
        $row = $this->database->query(<<<'SQL'
            SELECT space.id
            FROM agent_spaces AS space
            JOIN space_bindings AS binding ON binding.space_id = space.id
            JOIN space_releases AS release
                ON release.id = space.active_release_id
                AND release.space_id = space.id
            WHERE
                space.status = 'active'
                AND binding.bot_instance_id = ?
                AND binding.platform = 'telegram'
                AND binding.external_thread_id = ''
            ORDER BY space.created_at ASC, space.id ASC
            LIMIT 1
            SQL, [$this->botInstanceId])->fetch();
        $spaceId = is_array($row) ? ($row['id'] ?? null) : null;
        if ($spaceId === null) {
            return null;
        }
        if (!is_string($spaceId) || trim($spaceId) === '') {
            throw new RuntimeException('Agent worker preflight found an invalid Space identity.');
        }

        return SpaceId::assert($spaceId);
    }

    /**
     * @param class-string $workflowClass
     * @param string       $lane
     * @param string       $responsePrefix
     * @param string       $taskQueue
     * @param string       $releaseId
     * @param string       $attemptId
     * @param list<string> $startArgs
     *
     * @return array{
     *     lane: string,
     *     taskQueue: string,
     *     workflowId: string,
     *     runId: ?string,
     *     runtimeSnapshotChecked?: bool
     * }
     */
    private function check(
        string $lane,
        string $workflowClass,
        string $responsePrefix,
        string $taskQueue,
        string $releaseId,
        string $attemptId,
        array $startArgs,
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
        $run      = $this->client->start($workflow, ...$startArgs);
        $expected = implode(':', [$responsePrefix, $releaseId, $attemptId]);
        $actual   = $run->getResult('string', self::RESULT_TIMEOUT_SECONDS);
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new RuntimeException(sprintf(
                'Temporal %s worker preflight returned an invalid response.',
                $lane,
            ));
        }

        $report = [
            'lane'       => $lane,
            'taskQueue'  => $taskQueue,
            'workflowId' => $workflowId,
            'runId'      => $run->getExecution()->getRunID(),
        ];
        if ($lane === 'agent') {
            $report['runtimeSnapshotChecked'] = ($startArgs[2] ?? null) !== null;
        }

        return $report;
    }
}
