<?php

declare(strict_types=1);

namespace Bot\Space\Operations;

final class AgentWorkerHealthWorkflow implements AgentWorkerHealthWorkflowInterface
{
    public function check(string $releaseId, string $attemptId): string
    {
        return implode(':', [self::RESPONSE_PREFIX, $releaseId, $attemptId]);
    }
}
