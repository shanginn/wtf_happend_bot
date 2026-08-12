<?php

declare(strict_types=1);

namespace Tests\Temporal;

use Bot\AgenticWorkflow\AgenticWorkflow;
use Bot\Space\Operations\AgentWorkerHealthWorkflow;
use Bot\Space\Operations\AgentWorkerHealthWorkflowInterface;
use Bot\Space\Operations\DreamWorkerHealthWorkflow;
use Bot\Space\Operations\DreamWorkerHealthWorkflowInterface;
use PiPHP\Temporal\Workflow\DurableAgentWorkflow;
use PiPHP\Temporal\Workflow\DurableAgentWorkflowInterface;
use Spiral\Attributes\AttributeReader;
use Temporal\Internal\Declaration\Reader\WorkflowReader;
use Tests\TestCase;

class WorkflowTypeTest extends TestCase
{
    public function testWorkflowTypesUseStableRegisteredNames(): void
    {
        $reader = new WorkflowReader(new AttributeReader());

        self::assertSame(AgenticWorkflow::WORKFLOW_TYPE, $reader->fromClass(AgenticWorkflow::class)->getID());
        self::assertSame(
            DurableAgentWorkflowInterface::TYPE,
            $reader->fromClass(DurableAgentWorkflow::class)->getID(),
        );
    }

    public function testReleaseHealthWorkflowTypesAreStable(): void
    {
        $reader = new WorkflowReader(new AttributeReader());

        self::assertSame(
            AgentWorkerHealthWorkflowInterface::TYPE,
            $reader->fromClass(AgentWorkerHealthWorkflow::class)->getID(),
        );
        self::assertSame(
            DreamWorkerHealthWorkflowInterface::TYPE,
            $reader->fromClass(DreamWorkerHealthWorkflow::class)->getID(),
        );
    }

    public function testReleaseHealthWorkflowsAreRegisteredOnTheirDedicatedPackages(): void
    {
        $declarations = file_get_contents(dirname(__DIR__, 2) . '/config/declarations.php');
        self::assertIsString($declarations);
        self::assertMatchesRegularExpression(
            "/'space-agent-v1'\\s*=>\\s*\\[.*?'workflows'\\s*=>\\s*\\[\\s*AgentWorkerHealthWorkflow::class,/s",
            $declarations,
        );
        self::assertMatchesRegularExpression(
            "/'space-dream-v1'\\s*=>\\s*\\[.*?'workflows'\\s*=>\\s*\\[\\s*DreamWorkerHealthWorkflow::class,/s",
            $declarations,
        );
    }

    public function testAgenticWorkflowRegistersPauseAndResumeSignals(): void
    {
        $workflow = (new WorkflowReader(new AttributeReader()))->fromClass(AgenticWorkflow::class);
        $signals  = $workflow->getSignalHandlers();

        self::assertArrayHasKey(AgenticWorkflow::PAUSE_SIGNAL_NAME, $signals);
        self::assertArrayHasKey(AgenticWorkflow::RESUME_SIGNAL_NAME, $signals);
    }

    public function testPiPHPDurableWorkflowRegistersAgentControlSignals(): void
    {
        $workflow = (new WorkflowReader(new AttributeReader()))->fromClass(DurableAgentWorkflow::class);
        $signals  = $workflow->getSignalHandlers();

        self::assertArrayHasKey('steer', $signals);
        self::assertArrayHasKey('followUp', $signals);
        self::assertArrayHasKey('requestStop', $signals);
        self::assertArrayHasKey('snapshot', $workflow->getQueryHandlers());
    }
}
