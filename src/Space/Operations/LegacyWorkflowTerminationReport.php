<?php

declare(strict_types=1);

namespace Bot\Space\Operations;

final readonly class LegacyWorkflowTerminationReport
{
    /**
     * @param list<array{workflowId: string, runId: ?string, workflowType: string}> $executions
     * @param bool                                                                  $applied
     */
    public function __construct(
        public bool $applied,
        public array $executions,
    ) {}
}
