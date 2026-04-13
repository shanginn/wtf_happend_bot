<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\RelevantMemoriesAgent;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
use Shanginn\Openai\Openai;
use Tests\TestCase;

class RelevantMemoriesAgentTest extends TestCase
{
    public function testRecollectUsesDedicatedSelectionPrompt(): void
    {
        $history = [new UserMessage('who owns deploys?')];
        $allMemories = <<<TEXT
            All participant memories:
            - @alice | memory: Alice owns deploys | quote: I am on call for deploys | context: Release planning
            - @bob | memory: Bob likes tea | quote: tea is great | context: casual chat
            TEXT;
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
                $this->assertStringContainsString('relevant memories agent', $system);
                $this->assertStringContainsString('smallest subset that directly changes the next reply', $system);
                $this->assertStringContainsString('No preamble. No summary. No commentary.', $system);
                $this->assertStringContainsString(
                    'Drop anything merely related, generally useful, weakly connected, or redundant.',
                    (string) $messages[1]->content,
                );

                return $expectedResponse;
            });

        $result = (new RelevantMemoriesAgent($openai))->recollect($history, $allMemories);

        $this->assertSame($expectedResponse, $result);
    }
}
