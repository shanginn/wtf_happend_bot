<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface SpaceDreamWorkflowInterface
{
    public const TYPE = 'SpaceDreamWorkflowV1';

    #[WorkflowMethod(name: self::TYPE)]
    public function run(SpaceDreamInput $input): DreamOutcome;
}
