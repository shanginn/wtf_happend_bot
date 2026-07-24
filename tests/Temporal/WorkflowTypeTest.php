<?php

declare(strict_types=1);

namespace Tests\Temporal;

use Bot\AgenticWorkflow\AgenticWorkflow;
use Bot\RouterWorkflow\RouterWorkflow;
use Spiral\Attributes\AttributeReader;
use Temporal\Internal\Declaration\Reader\WorkflowReader;
use Tests\TestCase;

class WorkflowTypeTest extends TestCase
{
    public function testWorkflowTypesUseStableRegisteredNames(): void
    {
        $reader = new WorkflowReader(new AttributeReader());

        self::assertSame(AgenticWorkflow::WORKFLOW_TYPE, $reader->fromClass(AgenticWorkflow::class)->getID());
        self::assertSame(RouterWorkflow::WORKFLOW_TYPE, $reader->fromClass(RouterWorkflow::class)->getID());
    }

    public function testAgenticWorkflowRegistersPauseAndResumeSignals(): void
    {
        $workflow = (new WorkflowReader(new AttributeReader()))->fromClass(AgenticWorkflow::class);
        $signals = $workflow->getSignalHandlers();

        self::assertArrayHasKey(AgenticWorkflow::PAUSE_SIGNAL_NAME, $signals);
        self::assertArrayHasKey(AgenticWorkflow::RESUME_SIGNAL_NAME, $signals);
    }
}
