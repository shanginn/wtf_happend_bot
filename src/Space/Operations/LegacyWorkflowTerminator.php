<?php

declare(strict_types=1);

namespace Bot\Space\Operations;

use Bot\Config\TemporalExecutionIdentity;
use RuntimeException;
use Temporal\Client\GRPC\StatusCode;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Exception\Client\ServiceClientException;
use Temporal\Exception\Client\WorkflowNotFoundException;

final readonly class LegacyWorkflowTerminator
{
    private const array LEGACY_WORKFLOW_TYPES = [
        'AgenticWorkflow',
        'RouterWorkflow',
        'SpaceAgentWorkflowV1',
        'DreamCoordinatorWorkflowV1',
        'SpaceDreamWorkflowV1',
    ];

    private const int MAX_CONVERGENCE_PASSES = 60;
    private const int REQUIRED_CLEAN_PASSES  = 5;

    public function __construct(
        private WorkflowClientInterface $client,
        private string $hostReleaseId,
    ) {
        TemporalExecutionIdentity::assertReleaseId($hostReleaseId);
    }

    public function run(bool $apply): LegacyWorkflowTerminationReport
    {
        /** @var array<string, array{workflowId: string, runId: ?string, workflowType: string}> $executions */
        $executions  = [];
        $cleanPasses = 0;

        for ($pass = 1; $pass <= ($apply ? self::MAX_CONVERGENCE_PASSES : 1); ++$pass) {
            $foundThisPass = false;

            foreach (self::LEGACY_WORKFLOW_TYPES as $workflowType) {
                $query = sprintf(
                    "ExecutionStatus = 'Running' AND WorkflowType = '%s'",
                    $workflowType,
                );
                foreach ($this->client->listWorkflowExecutions($query, pageSize: 100) as $info) {
                    $workflowId = $info->execution->getID();
                    $runId      = $info->execution->getRunID();
                    if (TemporalExecutionIdentity::belongsToRelease($workflowId, $this->hostReleaseId)) {
                        continue;
                    }

                    $foundThisPass    = true;
                    $key              = $workflowType . "\0" . $workflowId . "\0" . ($runId ?? '');
                    $executions[$key] = [
                        'workflowId'   => $workflowId,
                        'runId'        => $runId,
                        'workflowType' => $workflowType,
                    ];

                    if (!$apply) {
                        continue;
                    }

                    try {
                        $this->client
                            ->newUntypedRunningWorkflowStub($workflowId, $runId, $workflowType)
                            ->terminate(
                                reason: 'Clean cutover to the new host release',
                                details: [
                                    'replacementWorkflowType' => 'SpaceAgentWorkflowV1',
                                    'replacementReleaseId'    => $this->hostReleaseId,
                                ],
                            );
                    } catch (WorkflowNotFoundException) {
                        // Visibility can lag a close or continue-as-new. The
                        // next bounded pass catches a newly visible successor.
                    } catch (ServiceClientException $error) {
                        if ($error->getCode() !== StatusCode::NOT_FOUND) {
                            throw $error;
                        }
                        // Same race as above, before the SDK maps the status.
                    }
                }
            }

            if (!$apply) {
                break;
            }

            if ($foundThisPass) {
                $cleanPasses = 0;
            } else {
                ++$cleanPasses;
                if ($cleanPasses >= self::REQUIRED_CLEAN_PASSES) {
                    return new LegacyWorkflowTerminationReport(true, array_values($executions));
                }
            }

            usleep(500_000);
        }

        if ($apply) {
            throw new RuntimeException(sprintf(
                'Legacy Temporal executions did not converge after %d passes.',
                self::MAX_CONVERGENCE_PASSES,
            ));
        }

        return new LegacyWorkflowTerminationReport(false, array_values($executions));
    }
}
