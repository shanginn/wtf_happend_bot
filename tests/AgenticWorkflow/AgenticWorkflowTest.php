<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\AgenticWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class AgenticWorkflowTest extends TestCase
{
    /**
     * @return iterable<string, array{pendingSince: int, now: int, expected: bool}>
     */
    public static function pipelineBatchWindowCases(): iterable
    {
        yield 'no pending pipeline' => [0, 105, false];
        yield 'inside batch window' => [100, 104, false];
        yield 'at batch deadline' => [100, 105, true];
        yield 'after batch deadline' => [100, 106, true];
    }

    #[DataProvider('pipelineBatchWindowCases')]
    public function testPipelineRunsOnlyAfterBatchWindow(int $pendingSince, int $now, bool $expected): void
    {
        $reflection = new ReflectionClass(AgenticWorkflow::class);
        $workflow = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('pipelinePendingSince')->setValue($workflow, $pendingSince);

        $method = new ReflectionMethod(AgenticWorkflow::class, 'shouldRunPipelineAt');

        self::assertSame($expected, $method->invoke($workflow, $now));
    }

    public function testCompactionRetryBackoffIsCapped(): void
    {
        $method = new ReflectionMethod(AgenticWorkflow::class, 'compactionRetryDelaySeconds');

        self::assertSame(300, $method->invoke(null, 1));
        self::assertSame(600, $method->invoke(null, 2));
        self::assertSame(1200, $method->invoke(null, 3));
        self::assertSame(2400, $method->invoke(null, 4));
        self::assertSame(3600, $method->invoke(null, 5));
        self::assertSame(3600, $method->invoke(null, 10));
    }
}
