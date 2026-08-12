<?php

declare(strict_types=1);

namespace Bot\Space\Operations;

final class DreamWorkerHealthWorkflow implements DreamWorkerHealthWorkflowInterface
{
    public function check(string $releaseId, string $attemptId): string
    {
        return implode(':', [self::RESPONSE_PREFIX, $releaseId, $attemptId]);
    }
}
