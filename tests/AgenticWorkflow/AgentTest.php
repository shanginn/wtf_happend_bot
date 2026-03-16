<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\Agent\UpdateTransformerInterface;
use Bot\AgenticWorkflow\Agent;
use Bot\Llm\Tools\Decision\RespondDecision;
use Bot\Telegram\TelegramUpdateView;
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

        $viewA = new TelegramUpdateView(text: 'first');
        $viewB = new TelegramUpdateView(text: 'second');
        $messageA = new UserMessage('first as llm message');
        $messageB = new UserMessage('second as llm message');

        $viewFactory = $this->createMock(TelegramUpdateViewFactoryInterface::class);
        $viewFactory
            ->expects($this->exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($viewA, $viewB);

        $transformer = $this->createMock(UpdateTransformerInterface::class);
        $transformer
            ->expects($this->exactly(2))
            ->method('toChatUserMessage')
            ->willReturnOnConsecutiveCalls($messageA, $messageB);

        $agent = new Agent(
            openai: $this->createStub(Openai::class),
            updateViewFactory: $viewFactory,
            updateTransformer: $transformer,
        );

        $this->assertSame([$messageA, $messageB], $agent->transformUpdates([$updateA, $updateB]));
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
}
