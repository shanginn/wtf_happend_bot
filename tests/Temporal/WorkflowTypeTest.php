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
}
