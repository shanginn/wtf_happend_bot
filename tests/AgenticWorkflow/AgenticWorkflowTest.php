<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\AgenticWorkflow;
use Bot\AgenticWorkflow\WorkingMemory;
use Bot\Llm\Tools\Decision\RespondDecision;
use Bot\Llm\Tools\Memory\SaveMemory;
use Bot\Llm\Tools\Runtime\ListRuntimeCapabilities;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Shanginn\Openai\ChatCompletion\Message\Assistant\KnownFunctionCall;
use Shanginn\Openai\ChatCompletion\Message\AssistantMessage;
use Shanginn\Openai\ChatCompletion\Message\ToolMessage;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
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

    public function testDecisionMemoryToolResultsAreRememberedWithoutRespondDecision(): void
    {
        $reflection = new ReflectionClass(AgenticWorkflow::class);
        $workflow = $reflection->newInstanceWithoutConstructor();
        $workingMemory = new WorkingMemory(memories: [new UserMessage('remember deploy owner')]);

        $reflection->getProperty('workingMemory')->setValue($workflow, $workingMemory);

        $saveMemoryCall = new KnownFunctionCall(
            id: 'call_save',
            tool: SaveMemory::class,
            arguments: new SaveMemory(
                userIdentifier: '@alice',
                memory: 'Alice owns deploys',
                quote: 'I own deploys',
                context: 'Release planning',
            ),
        );
        $respondDecisionCall = new KnownFunctionCall(
            id: 'call_decision',
            tool: RespondDecision::class,
            arguments: new RespondDecision(
                overview: 'The user supplied a durable fact.',
                shouldRespond: false,
            ),
        );
        $assistantMessage = new AssistantMessage(toolCalls: [$saveMemoryCall, $respondDecisionCall]);
        $toolMessage = new ToolMessage(
            content: 'Memory saved for @alice: Alice owns deploys',
            toolCallId: 'call_save',
        );

        $method = new ReflectionMethod(AgenticWorkflow::class, 'rememberDecisionToolResults');
        $method->invoke($workflow, $assistantMessage, [$saveMemoryCall], [$toolMessage]);

        $context = $workingMemory->getContext();

        self::assertCount(3, $context);
        self::assertInstanceOf(UserMessage::class, $context[0]);
        self::assertInstanceOf(AssistantMessage::class, $context[1]);
        self::assertInstanceOf(ToolMessage::class, $context[2]);
        self::assertCount(1, $context[1]->toolCalls);
        self::assertSame('call_save', $context[1]->toolCalls[0]->id);
        self::assertSame('call_save', $context[2]->toolCallId);
    }

    public function testDecisionPhaseDoesNotExecuteNonDecisionStaticTools(): void
    {
        $reflection = new ReflectionClass(AgenticWorkflow::class);
        $workflow = $reflection->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AgenticWorkflow::class, 'isExecutableDecisionToolCall');

        $toolCall = new KnownFunctionCall(
            id: 'call_list',
            tool: ListRuntimeCapabilities::class,
            arguments: new ListRuntimeCapabilities(kind: 'tool'),
        );

        self::assertFalse($method->invoke($workflow, $toolCall));
    }

    public function testDecisionFallbackRespondsOnlyForBotDirectedMessages(): void
    {
        $reflection = new ReflectionClass(AgenticWorkflow::class);
        $workflow = $reflection->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AgenticWorkflow::class, 'shouldFallbackRespond');

        self::assertTrue($method->invoke($workflow, [
            new UserMessage("Telegram update: message\n\nText:\nбот почему инструментов нет в списке?"),
        ]));
        self::assertFalse($method->invoke($workflow, [
            new UserMessage("Telegram update: message\n\nText:\nобычный разговор без обращения"),
        ]));
    }
}
