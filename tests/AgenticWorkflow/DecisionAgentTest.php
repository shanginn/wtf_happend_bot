<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\DecisionAgent;
use Bot\Llm\Runtime\RuntimeToolDefinition;
use Bot\Llm\Tools\Decision\RespondDecision;
use Bot\Llm\Tools\Memory\SaveMemory;
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

    public function testDecideListsRuntimeToolsButDoesNotExposeThemAsCallableDecisionTools(): void
    {
        $history = [new UserMessage('/whois @alice')];
        $runtimeTool = new RuntimeToolDefinition(
            name: 'whois',
            description: 'Summarizes known participant information from supplied arguments.',
            parametersSchema: ['type' => 'object'],
            instructions: 'Return only known participant facts from arguments.',
        );
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
                $this->assertStringContainsString('<tool name="whois"', $system);
                $this->assertStringContainsString('set `shouldRespond=true`', $system);
                $this->assertContains(RespondDecision::class, $tools);
                $this->assertContains(SaveMemory::class, $tools);
                $this->assertNotContains('whois', $tools);

                return $expectedResponse;
            });

        $result = (new DecisionAgent($openai))->decide(
            history: $history,
            tools: [SaveMemory::class, $runtimeTool],
        );

        $this->assertSame($expectedResponse, $result);
    }
}
