<?php

declare(strict_types=1);

namespace Bot\Space\Operations;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface DreamWorkerHealthWorkflowInterface
{
    public const TYPE            = 'SpaceDreamWorkerHealthV1';
    public const RESPONSE_PREFIX = 'space-dream-worker-ready/v1';

    #[WorkflowMethod(name: self::TYPE)]
    public function check(string $releaseId, string $attemptId): string;
}
