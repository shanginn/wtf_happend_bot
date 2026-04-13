<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\ResponseAgent;
use Bot\Llm\Tools\Chat\GetCurrentTime;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
use Shanginn\Openai\Openai;
use Tests\TestCase;

class ResponseAgentTest extends TestCase
{
    public function testRespondUsesDedicatedResponsePrompt(): void
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
                $this->assertStringContainsString('response agent', $system);
                $this->assertStringContainsString('already decided that the bot should respond', $system);
                $this->assertSame([GetCurrentTime::class], $tools);

                return $expectedResponse;
            });

        $result = (new ResponseAgent($openai))->respond($history, [GetCurrentTime::class]);

        $this->assertSame($expectedResponse, $result);
    }

    public function testRecollectRelevantMemoriesUsesMemorySelectionPrompt(): void
    {
        $history = [new UserMessage('hello')];
        $allMemories = "All participant memories:\n- @alice | memory: Alice owns deploys";
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
            ) use ($history, $allMemories, $expectedResponse) {
                $this->assertCount(2, $messages);
                $this->assertSame($history[0], $messages[0]);
                $this->assertStringContainsString($allMemories, (string) $messages[1]->content);
                $this->assertIsString($system);
                $this->assertStringContainsString('selecting persistent participant memories for the response agent', $system);

                return $expectedResponse;
            });

        $result = (new ResponseAgent($openai))->recollectRelevantMemories($history, $allMemories);

        $this->assertSame($expectedResponse, $result);
    }
}
