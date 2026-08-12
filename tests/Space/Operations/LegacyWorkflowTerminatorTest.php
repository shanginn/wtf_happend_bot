<?php

declare(strict_types=1);

namespace Tests\Space\Operations;

use Bot\Space\Operations\LegacyWorkflowTerminator;
use Generator;
use Mockery;
use stdClass;
use Temporal\Client\Common\Paginator;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Workflow\WorkflowExecution;
use Tests\TestCase;

final class LegacyWorkflowTerminatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testDryRunListsOldFamiliesButExcludesCurrentRelease(): void
    {
        $releaseId = str_repeat('a', 64);
        $client    = Mockery::mock(WorkflowClientInterface::class);
        $client->shouldNotReceive('newUntypedRunningWorkflowStub');
        $client->shouldReceive('listWorkflowExecutions')
            ->times(5)
            ->andReturnUsing(static function (string $query) use ($releaseId): Paginator {
                $type = match (true) {
                    str_contains($query, "'AgenticWorkflow'")            => 'AgenticWorkflow',
                    str_contains($query, "'RouterWorkflow'")             => 'RouterWorkflow',
                    str_contains($query, "'SpaceAgentWorkflowV1'")       => 'SpaceAgentWorkflowV1',
                    str_contains($query, "'DreamCoordinatorWorkflowV1'") => 'DreamCoordinatorWorkflowV1',
                    default                                              => 'SpaceDreamWorkflowV1',
                };
                $items = [];
                if ($type === 'SpaceAgentWorkflowV1') {
                    $old                = new stdClass();
                    $old->execution     = new WorkflowExecution('space-agent/spc_1/v1/release/old', 'old-run');
                    $current            = new stdClass();
                    $current->execution = new WorkflowExecution(
                        "space-agent/spc_2/v1/release/{$releaseId}",
                        'new-run',
                    );
                    $items = [$old, $current];
                }

                return self::paginator($items);
            });

        $report = (new LegacyWorkflowTerminator($client, $releaseId))->run(false);

        self::assertFalse($report->applied);
        self::assertSame([[
            'workflowId'   => 'space-agent/spc_1/v1/release/old',
            'runId'        => 'old-run',
            'workflowType' => 'SpaceAgentWorkflowV1',
        ]], $report->executions);
    }

    /**
     * @param list<object> $items
     */
    private static function paginator(array $items): Paginator
    {
        $generator = (static function () use ($items): Generator {
            yield $items;
        })();

        return Paginator::createFromGenerator($generator, null);
    }
}
