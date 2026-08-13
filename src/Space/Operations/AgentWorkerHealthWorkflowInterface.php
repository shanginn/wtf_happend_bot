<?php

declare(strict_types=1);

namespace Bot\Space\Operations;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface AgentWorkerHealthWorkflowInterface
{
    public const TYPE            = 'SpaceAgentWorkerHealthV1';
    public const RESPONSE_PREFIX = 'space-agent-worker-ready/v1';

    #[WorkflowMethod(name: self::TYPE)]
    public function check(string $releaseId, string $attemptId, ?string $spaceId): string;
}
