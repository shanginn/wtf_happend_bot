<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\RelevantMemoriesAgent;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\CompletionResponse\Choice;
use Shanginn\Openai\ChatCompletion\CompletionResponse\Usage;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\AssistantMessage;
use Shanginn\Openai\ChatCompletion\Message\SystemMessage;
use Shanginn\Openai\ChatCompletion\Message\ToolMessage;
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
                $this->assertStringContainsString('Never answer the user, call a tool, imitate a tool call', $system);
                $this->assertStringContainsString(
                    'Drop anything merely related, generally useful, weakly connected, or redundant.',
                    (string) $messages[1]->content,
                );

                return $expectedResponse;
            });

        $result = (new RelevantMemoriesAgent($openai))->recollect($history, $allMemories);

        $this->assertSame($expectedResponse, $result);
    }

    public function testRecollectRejectsDsmlResponseAsNoRelevantMemories(): void
    {
        $allMemories = <<<TEXT
            All participant memories:
            - Ник | memory: Ник owns deploys | quote: Я отвечаю за деплой | context: Release planning | updated: 2026-08-05
            TEXT;
        $invalidSelection = <<<'TEXT'
            Ник кричит «БОООООТ» — это призыв к боту. Отвечу в духе чата.

            <｜｜DSML｜｜tool_calls>
            <｜｜DSML｜｜invoke name="telegram_api_call">
            <｜｜DSML｜｜parameter name="method" string="true">sendMessage</｜｜DSML｜｜parameter>
            <｜｜DSML｜｜parameter name="parameters" string="false">{"text":"Бот тут!"}</｜｜DSML｜｜parameter>
            </｜｜DSML｜｜invoke>
            </｜｜DSML｜｜tool_calls>
            TEXT;

        $openai = $this->createMock(Openai::class);
        $openai
            ->expects($this->once())
            ->method('completion')
            ->willReturn(self::completion($invalidSelection));

        $result = (new RelevantMemoriesAgent($openai))->recollect(
            [new UserMessage('БОООООТ', 'Ник')],
            $allMemories,
        );

        $this->assertSame('No relevant memories.', $result->choices[0]->message->content);
        $this->assertNull($result->choices[0]->message->toolCalls);
    }

    public function testRecollectReturnsOnlyCanonicalStoredMemoryBullets(): void
    {
        $allMemories = <<<TEXT
            All participant memories:
            - @alice | memory: Alice owns deploys | quote: I am on call | context: Release planning | updated: 2026-08-05
            - @bob | memory: Bob likes tea | quote: Tea is great | context: Casual chat | updated: 2026-08-04
            TEXT;
        $selection = '- @alice | memory: Alice owns deploys | quote: I am on call | context: Release planning';

        $openai = $this->createMock(Openai::class);
        $openai
            ->expects($this->once())
            ->method('completion')
            ->willReturn(self::completion($selection));

        $result = (new RelevantMemoriesAgent($openai))->recollect(
            [new UserMessage('who owns deploys?')],
            $allMemories,
        );

        $this->assertSame($selection, $result->choices[0]->message->content);
    }

    public function testRecollectExcludesInternalTranscriptFromSelectionContext(): void
    {
        $history = [
            new SystemMessage('Compacted context with stale bot instructions'),
            new AssistantMessage('The message was sent successfully.'),
            new ToolMessage('Telegram API call succeeded: {"ok":true}', 'call_1'),
            new UserMessage('БОООООТ', 'Ник'),
        ];

        $openai = $this->createMock(Openai::class);
        $openai
            ->expects($this->once())
            ->method('completion')
            ->willReturnCallback(function (array $messages): CompletionResponse {
                $this->assertCount(2, $messages);
                $this->assertSame('БОООООТ', $messages[0]->content);
                $this->assertSame('Ник', $messages[0]->name);
                $this->assertStringNotContainsString(
                    'The message was sent successfully.',
                    implode("\n", array_map(
                        static fn (UserMessage $message): string => (string) $message->content,
                        $messages,
                    )),
                );

                return self::completion('No relevant memories.');
            });

        $result = (new RelevantMemoriesAgent($openai))->recollect(
            $history,
            'All participant memories:',
        );

        $this->assertSame('No relevant memories.', $result->choices[0]->message->content);
    }

    private static function completion(string $content): CompletionResponse
    {
        return new CompletionResponse(
            id: 'memory-selection',
            choices: [
                new Choice(
                    index: 0,
                    message: new AssistantMessage($content),
                    finishReason: 'stop',
                ),
            ],
            model: 'test-model',
            usage: new Usage(completionTokens: 1, promptTokens: 1, totalTokens: 2),
            object: 'chat.completion',
            created: 1,
        );
    }
}
