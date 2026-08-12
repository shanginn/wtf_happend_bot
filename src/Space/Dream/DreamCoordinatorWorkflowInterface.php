<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface DreamCoordinatorWorkflowInterface
{
    public const TYPE = 'DreamCoordinatorWorkflowV1';

    /**
     * @return array{dreamDate: string, attempted: int, completed: int, failed: int}
     */
    #[WorkflowMethod(name: self::TYPE)]
    public function run(DreamCoordinatorInput $input): array;
}
