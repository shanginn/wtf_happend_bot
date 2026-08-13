<?php

declare(strict_types=1);

namespace Tests\Space\Dream;

use Bot\Space\Dream\DreamCoordinatorWorkflow;
use Bot\Space\Dream\SpaceDreamWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use Temporal\Exception\OutOfContextException;
use Tests\TestCase;

final class DreamWorkflowFacadeTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function workflowClasses(): iterable
    {
        yield 'coordinator' => [DreamCoordinatorWorkflow::class];
        yield 'space dream' => [SpaceDreamWorkflow::class];
    }

    #[DataProvider('workflowClasses')]
    public function testWorkflowConstructorsReachTheTemporalFacade(string $workflowClass): void
    {
        $this->expectException(OutOfContextException::class);
        $this->expectExceptionMessage('The Workflow facade can be used only inside workflow code.');

        new $workflowClass();
    }
}
