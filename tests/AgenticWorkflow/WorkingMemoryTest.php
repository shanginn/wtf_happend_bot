<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\WorkingMemory;
use Bot\Llm\Tools\Memory\SaveMemory;
use Shanginn\Openai\ChatCompletion\Message\Assistant\KnownFunctionCall;
use Shanginn\Openai\ChatCompletion\Message\AssistantMessage;
use Shanginn\Openai\ChatCompletion\Message\SystemMessage;
use Shanginn\Openai\ChatCompletion\Message\ToolMessage;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
use Tests\TestCase;

class WorkingMemoryTest extends TestCase
{
    public function testGetContextPrependsCompactedContext(): void
    {
        $memory = new WorkingMemory(
            memories: [new UserMessage('latest message')],
            compactedContext: "- Alice owns deploys\n- Bob asked for a rollback plan",
        );

        $context = $memory->getContext();

        self::assertCount(2, $context);
        self::assertInstanceOf(SystemMessage::class, $context[0]);
        self::assertStringContainsString('Compacted context from earlier conversation:', $context[0]->content);
        self::assertStringContainsString('Alice owns deploys', $context[0]->content);
        self::assertInstanceOf(UserMessage::class, $context[1]);
        self::assertSame('latest message', $context[1]->content);
    }

    public function testCompactKeepsRecentMessagesAndStoresCompactedContext(): void
    {
        $messages = [];

        for ($index = 1; $index <= 55; ++$index) {
            $messages[] = new UserMessage('message ' . $index);
        }

        $memory = new WorkingMemory(memories: $messages);

        self::assertCount(5, $memory->getMessagesToCompact());

        $memory->compact("- Project launch is blocked on infra\n- Alice owns deploys");

        self::assertSame(
            "- Project launch is blocked on infra\n- Alice owns deploys",
            $memory->getCompactedContext(),
        );
        self::assertCount(50, $memory->get());
        self::assertSame('message 6', $memory->get()[0]->content);
        self::assertSame('message 55', $memory->get()[49]->content);
    }

    public function testMessagesToCompactUseTokenBudgetInsteadOfMessageCount(): void
    {
        $messages = [];

        for ($index = 1; $index <= 250; ++$index) {
            $messages[] = new UserMessage('message ' . $index);
        }

        $memory = new WorkingMemory(memories: $messages);

        self::assertCount(200, $memory->getMessagesToCompact());
    }

    public function testMessagesToCompactAreCappedByApproximateTokenBudget(): void
    {
        $messages = [];

        for ($index = 1; $index <= 3; ++$index) {
            $messages[] = new UserMessage(str_repeat('x', 60000));
        }

        for ($index = 1; $index <= 50; ++$index) {
            $messages[] = new UserMessage('recent ' . $index);
        }

        $memory = new WorkingMemory(memories: $messages);

        self::assertCount(1, $memory->getMessagesToCompact());
    }

    public function testCompactDoesNotSplitAssistantToolCallGroup(): void
    {
        $toolCall = new KnownFunctionCall(
            id: 'call_1',
            tool: SaveMemory::class,
            arguments: new SaveMemory('@alice', 'Alice owns deploys', 'I own deploys', 'Release planning'),
        );

        $messages = [
            new UserMessage('old message'),
            new AssistantMessage(toolCalls: [$toolCall]),
            new ToolMessage('saved', 'call_1'),
        ];

        for ($index = 1; $index <= 49; ++$index) {
            $messages[] = new UserMessage('recent ' . $index);
        }

        $memory = new WorkingMemory(memories: $messages);

        self::assertCount(1, $memory->getMessagesToCompact());

        $memory->compact('- Older context');

        self::assertCount(51, $memory->get());
        self::assertInstanceOf(AssistantMessage::class, $memory->get()[0]);
        self::assertInstanceOf(ToolMessage::class, $memory->get()[1]);
    }

    public function testGetContextDropsInvalidToolMessageFragments(): void
    {
        $toolCall = new KnownFunctionCall(
            id: 'call_1',
            tool: SaveMemory::class,
            arguments: new SaveMemory('@alice', 'Alice owns deploys', 'I own deploys', 'Release planning'),
        );

        $memory = new WorkingMemory(memories: [
            new ToolMessage('orphaned', 'missing_call'),
            new AssistantMessage(toolCalls: [$toolCall]),
            new UserMessage('new topic'),
        ]);

        $context = $memory->getContext();

        self::assertCount(1, $context);
        self::assertInstanceOf(UserMessage::class, $context[0]);
        self::assertSame('new topic', $context[0]->content);
    }
}
