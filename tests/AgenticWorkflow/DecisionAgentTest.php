<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\DecisionAgent;
use Bot\Llm\Tools\Decision\RespondDecision;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
use Shanginn\Openai\Openai;
use Tests\TestCase;

class DecisionAgentTest extends TestCase
{
    public function testDecideUsesDedicatedDecisionPromptAndRespondDecisionTool(): void
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
                $this->assertStringContainsString('decision agent', $system);
                $this->assertStringContainsString('A decision is mandatory for every completion', $system);
                $this->assertStringContainsString('A completion without `respond_decision` is invalid', $system);
                $this->assertStringContainsString('Never write the final Telegram reply', $system);
                $this->assertContains(RespondDecision::class, $tools);

                return $expectedResponse;
            });

        $result = (new DecisionAgent($openai))->decide($history);

        $this->assertSame($expectedResponse, $result);
    }
}
