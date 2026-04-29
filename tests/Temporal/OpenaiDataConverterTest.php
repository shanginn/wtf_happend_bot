<?php

declare(strict_types=1);

namespace Tests\Temporal;

use Bot\AgenticWorkflow\AgenticToolset;
use Bot\Llm\Tools\Image\DownloadImage;
use Bot\Llm\Tools\Telegram\TelegramApiCall;
use Bot\Temporal\OpenaiDataConverter;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\CompletionResponse\Choice;
use Shanginn\Openai\ChatCompletion\CompletionResponse\Usage;
use Shanginn\Openai\ChatCompletion\Message\Assistant\KnownFunctionCall;
use Shanginn\Openai\ChatCompletion\Message\Assistant\UnknownFunctionCall;
use Shanginn\Openai\ChatCompletion\Message\AssistantMessage;
use Temporal\DataConverter\Type;
use Tests\TestCase;

class OpenaiDataConverterTest extends TestCase
{
    public function testAgenticToolsetRegistrationKeepsTelegramApiCallKnownAcrossPayloadRoundTrip(): void
    {
        $converter = new OpenaiDataConverter();
        $converter->registerTools(
            DownloadImage::class,
            ...AgenticToolset::TOOLS,
        );

        $response = new CompletionResponse(
            id: 'gen-telegram',
            choices: [
                new Choice(
                    index: 0,
                    message: new AssistantMessage(
                        toolCalls: [
                            new UnknownFunctionCall(
                                id: 'call_telegram',
                                name: 'telegram_api_call',
                                arguments: json_encode([
                                    'method' => 'sendMessage',
                                    'parameters' => ['text' => 'Hello'],
                                ], \JSON_THROW_ON_ERROR),
                            ),
                        ],
                    ),
                    finishReason: 'tool_calls',
                ),
            ],
            model: 'test-model',
            usage: new Usage(completionTokens: 1, promptTokens: 1, totalTokens: 2),
            object: 'chat.completion',
            created: 1,
        );

        $payload = $converter->toPayload($response);
        self::assertNotNull($payload);

        $roundTripped = $converter->fromPayload($payload, Type::create(CompletionResponse::class));
        $toolCall = $roundTripped->choices[0]->message->toolCalls[0] ?? null;

        self::assertInstanceOf(KnownFunctionCall::class, $toolCall);
        self::assertSame(TelegramApiCall::class, $toolCall->tool);
        self::assertSame('sendMessage', $toolCall->arguments->method);
        self::assertSame(['text' => 'Hello'], $toolCall->arguments->parameters);
    }

    public function testRuntimeUnknownToolCallStaysUnknownAcrossPayloadRoundTrip(): void
    {
        $converter = new OpenaiDataConverter();
        $converter->registerTools(
            DownloadImage::class,
            ...AgenticToolset::TOOLS,
        );

        $response = new CompletionResponse(
            id: 'gen-runtime',
            choices: [
                new Choice(
                    index: 0,
                    message: new AssistantMessage(
                        toolCalls: [
                            new UnknownFunctionCall(
                                id: 'call_runtime',
                                name: 'format_incident',
                                arguments: json_encode([
                                    'summary' => 'deploy failed',
                                ], \JSON_THROW_ON_ERROR),
                            ),
                        ],
                    ),
                    finishReason: 'tool_calls',
                ),
            ],
            model: 'test-model',
            usage: new Usage(completionTokens: 1, promptTokens: 1, totalTokens: 2),
            object: 'chat.completion',
            created: 1,
        );

        $payload = $converter->toPayload($response);
        self::assertNotNull($payload);

        $roundTripped = $converter->fromPayload($payload, Type::create(CompletionResponse::class));
        $toolCall = $roundTripped->choices[0]->message->toolCalls[0] ?? null;

        self::assertInstanceOf(UnknownFunctionCall::class, $toolCall);
        self::assertSame('format_incident', $toolCall->name);
        self::assertSame('{"summary":"deploy failed"}', $toolCall->arguments);
    }
}
