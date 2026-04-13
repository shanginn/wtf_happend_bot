<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\Agent;
use Bot\Llm\Tools\Decision\RespondDecision;
use Bot\Telegram\InputMessageView;
use Bot\Telegram\TelegramUpdateViewFactoryInterface;
use Phenogram\Bindings\Types\Update;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
use Shanginn\Openai\Openai;
use Tests\TestCase;

class AgentTest extends TestCase
{
    public function testTransformUpdatesBuildsProviderMessages(): void
    {
        $updateA = new Update(updateId: 1);
        $updateB = new Update(updateId: 2);

        $viewA = new InputMessageView(text: 'first', participantReference: 'alice');
        $viewB = new InputMessageView(text: 'second', participantReference: 'bob');

        $viewFactory = $this->createMock(TelegramUpdateViewFactoryInterface::class);
        $viewFactory
            ->expects($this->exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($viewA, $viewB);

        $agent = new Agent(
            openai: $this->createStub(Openai::class),
            updateViewFactory: $viewFactory,
        );

        $messages = $agent->transformUpdates([$updateA, $updateB]);

        $this->assertCount(2, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(UserMessage::class, $messages[1]);
        $this->assertSame('first', $messages[0]->content);
        $this->assertSame('alice', $messages[0]->name);
        $this->assertSame('second', $messages[1]->content);
        $this->assertSame('bob', $messages[1]->name);
    }

    public function testCompleteUsesHistoryAndEnsuresRespondDecisionTool(): void
    {
        $history = [new UserMessage('hello')];
        $expectedResponse = new ErrorResponse(
            message: 'synthetic',
            type: null,
            param: null,
            code: null,
            rawResponse: '',
        );

        $openai = $this->createMock(Openai::class);
        $openai
            ->expects($this->once())
            ->method('completion')
            ->willReturnCallback(function (
                array $messages,
                ?string $system = null,
                ?float $temperature = null,
                ?int $maxTokens = null,
                ?int $maxCompletionTokens = null,
                ?float $frequencyPenalty = null,
                mixed $toolChoice = null,
                ?array $tools = null,
            ) use ($history, $expectedResponse) {
                $this->assertSame($history, $messages);
                $this->assertIsString($system);
                $this->assertStringContainsString('respond_decision', $system);
                $this->assertContains(RespondDecision::class, $tools);

                return $expectedResponse;
            });

        $result = (new Agent($openai))->complete($history);

        $this->assertSame($expectedResponse, $result);
    }

    public function testCompleteReturnsErrorWithoutCallingOpenaiForEmptyHistory(): void
    {
        $openai = $this->createMock(Openai::class);
        $openai->expects($this->never())->method('completion');

        $result = (new Agent($openai))->complete([]);

        $this->assertInstanceOf(ErrorResponse::class, $result);
        $this->assertSame('No messages to process.', $result->message);
    }

    public function testRecollectRelevantMemoriesUsesDedicatedOpenaiWhenProvided(): void
    {
        $history = [new UserMessage('who owns deploys?')];
        $allMemories = "All participant memories:\n- @alice | memory: Alice owns deploys";
        $expectedResponse = new ErrorResponse(
            message: 'synthetic',
            type: null,
            param: null,
            code: null,
            rawResponse: '',
        );

        $responseOpenai = $this->createMock(Openai::class);
        $responseOpenai->expects($this->never())->method('completion');

        $memoryOpenai = $this->createMock(Openai::class);
        $memoryOpenai
            ->expects($this->once())
            ->method('completion')
            ->willReturn($expectedResponse);

        $result = (new Agent(
            openai: $responseOpenai,
            memoryRecollectionOpenai: $memoryOpenai,
        ))->recollectRelevantMemories($history, $allMemories);

        $this->assertSame($expectedResponse, $result);
    }
}
