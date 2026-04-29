<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\ResponseAgent;
use Bot\Llm\Runtime\RuntimeSkillDefinition;
use Bot\Llm\Runtime\RuntimeToolDefinition;
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
                mixed $responseFormat = null,
                ?float $topP = null,
                ?int $seed = null,
                ?string $reasoningEffort = null,
                ?array $extraBody = null,
            ) use ($history, $expectedResponse) {
                $this->assertSame($history, $messages);
                $this->assertIsString($system);
                $this->assertStringContainsString('response agent', $system);
                $this->assertStringContainsString('already decided that the bot should respond', $system);
                $this->assertSame([GetCurrentTime::class], $tools);
                $this->assertSame(['thinking' => ['type' => 'disabled']], $extraBody);

                return $expectedResponse;
            });

        $result = (new ResponseAgent($openai))->respond($history, [GetCurrentTime::class]);

        $this->assertSame($expectedResponse, $result);
    }

    public function testRespondIncludesRuntimeSkillsAndToolsInPrompt(): void
    {
        $history = [new UserMessage('format the incident')];
        $runtimeSkill = new RuntimeSkillDefinition(
            name: 'incident-style',
            description: 'Keeps incident replies terse.',
            body: 'Use terse incident-response phrasing.',
        );
        $runtimeTool = new RuntimeToolDefinition(
            name: 'format_incident',
            description: 'Formats incident facts.',
            parametersSchema: ['type' => 'object'],
            instructions: 'Return incident facts as bullets.',
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
            ) use ($history, $runtimeSkill, $runtimeTool, $expectedResponse) {
                $this->assertSame($history, $messages);
                $this->assertIsString($system);
                $this->assertStringContainsString('incident-style', $system);
                $this->assertStringContainsString('Use terse incident-response phrasing.', $system);
                $this->assertStringContainsString('format_incident', $system);
                $this->assertSame([$runtimeTool], $tools);

                return $expectedResponse;
            });

        $result = (new ResponseAgent($openai))->respond(
            history: $history,
            tools: [$runtimeTool],
            skills: [$runtimeSkill],
        );

        $this->assertSame($expectedResponse, $result);
    }
}
