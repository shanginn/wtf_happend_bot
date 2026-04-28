<?php

declare(strict_types=1);

namespace Tests\Openai;

use Bot\Llm\Tools\Chat\SearchMessages;
use Bot\Llm\Tools\Memory\ForgetMemory;
use Bot\Llm\Tools\Telegram\TelegramApiCall;
use Bot\Openai\CompatibleOpenai;
use Bot\Openai\CompatibleOpenaiSerializer;
use Shanginn\Openai\ChatCompletion\CompletionRequest;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\CompletionResponse\Choice;
use Shanginn\Openai\ChatCompletion\CompletionResponse\Usage;
use Shanginn\Openai\ChatCompletion\Message\Assistant\KnownFunctionCall;
use Shanginn\Openai\ChatCompletion\Message\Assistant\UnknownFunctionCall;
use Shanginn\Openai\ChatCompletion\Message\AssistantMessage;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
use Shanginn\Openai\Openai\OpenaiClientInterface;
use Tests\TestCase;

final class CompatibleOpenaiSerializerTest extends TestCase
{
    public function testSerializeTelegramApiCallParametersAsJsonObjectSchema(): void
    {
        $serializer = new CompatibleOpenaiSerializer();

        $serialized = $serializer->serialize(new CompletionRequest(
            model: 'test-model',
            messages: [],
            tools: [TelegramApiCall::class],
        ));

        $decoded = json_decode($serialized, true, flags: \JSON_THROW_ON_ERROR);
        $parameters = $decoded['tools'][0]['function']['parameters']['properties']['parameters'];

        self::assertSame('object', $parameters['type']);
        self::assertTrue($parameters['additionalProperties']);
        self::assertSame([], $parameters['default']);
        self::assertArrayNotHasKey('items', $parameters);
    }

    public function testDeserializeTelegramApiCallParametersObjectIntoArray(): void
    {
        $serializer = new CompatibleOpenaiSerializer();

        $response = $serializer->deserialize(
            serialized: json_encode([
                'id' => 'gen-telegram-1',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'tool_calls' => [[
                            'id' => 'call_telegram_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'telegram_api_call',
                                'arguments' => json_encode([
                                    'method' => 'sendMessage',
                                    'parameters' => ['text' => 'Hello'],
                                ], \JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'model' => 'qwen/qwen3.5-plus-20260216',
                'usage' => [
                    'completion_tokens' => 90,
                    'prompt_tokens' => 8617,
                    'total_tokens' => 8707,
                ],
                'object' => 'chat.completion',
                'created' => 1776020161,
            ], \JSON_THROW_ON_ERROR),
            to: CompletionResponse::class,
            tools: [TelegramApiCall::class],
        );

        $toolCall = $response->choices[0]->message->toolCalls[0] ?? null;

        self::assertInstanceOf(KnownFunctionCall::class, $toolCall);
        self::assertSame(TelegramApiCall::class, $toolCall->tool);
        self::assertSame('sendMessage', $toolCall->arguments->method);
        self::assertSame(['text' => 'Hello'], $toolCall->arguments->parameters);
    }

    public function testDeserializeFlatToolCallCoercesArgumentsIntoKnownFunctionCall(): void
    {
        $serializer = new CompatibleOpenaiSerializer();

        $response = $serializer->deserialize(
            serialized: json_encode([
                'id' => 'gen-1',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'name' => 'search_messages',
                            'arguments' => '{"query":"","username":"@readonIy","limit":"50"}',
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'model' => 'qwen/qwen3.5-plus-20260216',
                'usage' => [
                    'completion_tokens' => 90,
                    'prompt_tokens' => 8617,
                    'total_tokens' => 8707,
                ],
                'object' => 'chat.completion',
                'created' => 1776020161,
            ], \JSON_THROW_ON_ERROR),
            to: CompletionResponse::class,
            tools: [SearchMessages::class],
        );

        $toolCall = $response->choices[0]->message->toolCalls[0] ?? null;

        self::assertInstanceOf(KnownFunctionCall::class, $toolCall);
        self::assertSame(SearchMessages::class, $toolCall->tool);
        self::assertSame(50, $toolCall->arguments->limit);
        self::assertSame('@readonIy', $toolCall->arguments->username);
    }

    public function testSerializeCanonicalizesUnknownFunctionCallsForReplay(): void
    {
        $serializer = new CompatibleOpenaiSerializer();

        $response = new CompletionResponse(
            id: 'resp_1',
            choices: [
                new Choice(
                    index: 0,
                    message: new AssistantMessage(
                        toolCalls: [
                            new UnknownFunctionCall(
                                id: 'call_2',
                                name: 'missing_tool',
                                arguments: '{"foo":"bar"}',
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

        $serialized = $serializer->serialize($response);
        $decoded = json_decode($serialized, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('missing_tool', $decoded['choices'][0]['message']['tool_calls'][0]['function']['name']);
        self::assertArrayNotHasKey('name', $decoded['choices'][0]['message']['tool_calls'][0]);

        $roundTripped = $serializer->deserialize($serialized, CompletionResponse::class);
        $toolCall = $roundTripped->choices[0]->message->toolCalls[0] ?? null;

        self::assertInstanceOf(UnknownFunctionCall::class, $toolCall);
        self::assertSame('missing_tool', $toolCall->name);
        self::assertSame('{"foo":"bar"}', $toolCall->arguments);
    }

    public function testDeserializeToolCallAcceptsSnakeCaseArgumentAliases(): void
    {
        $serializer = new CompatibleOpenaiSerializer();

        $response = $serializer->deserialize(
            serialized: json_encode([
                'id' => 'gen-3',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'tool_calls' => [[
                            'id' => 'call_4',
                            'type' => 'function',
                            'function' => [
                                'name' => 'forget_memory',
                                'arguments' => '{"memory_id":"42","user_identifier":"@alice","query":"deploys","forget_all_for_participant":"true"}',
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'model' => 'qwen/qwen3.5-plus-20260216',
                'usage' => [
                    'completion_tokens' => 90,
                    'prompt_tokens' => 8617,
                    'total_tokens' => 8707,
                ],
                'object' => 'chat.completion',
                'created' => 1776020161,
            ], \JSON_THROW_ON_ERROR),
            to: CompletionResponse::class,
            tools: [ForgetMemory::class],
        );

        $toolCall = $response->choices[0]->message->toolCalls[0] ?? null;

        self::assertInstanceOf(KnownFunctionCall::class, $toolCall);
        self::assertSame(ForgetMemory::class, $toolCall->tool);
        self::assertSame(42, $toolCall->arguments->memoryId);
        self::assertSame('@alice', $toolCall->arguments->userIdentifier);
        self::assertSame('deploys', $toolCall->arguments->query);
        self::assertTrue($toolCall->arguments->forgetAllForParticipant);
    }

    public function testCompatibleOpenaiReturnsKnownToolCallForStringifiedScalarArguments(): void
    {
        $client = new class implements OpenaiClientInterface
        {
            public function sendRequest(string $method, string $json): string
            {
                return json_encode([
                    'id' => 'gen-2',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'tool_calls' => [[
                                'id' => 'call_3',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'search_messages',
                                    'arguments' => '{"query":"","username":"@readonIy","limit":"50"}',
                                ],
                            ]],
                        ],
                        'finish_reason' => 'tool_calls',
                    ]],
                    'model' => 'qwen/qwen3.5-plus-20260216',
                    'usage' => [
                        'completion_tokens' => 90,
                        'prompt_tokens' => 8617,
                        'total_tokens' => 8707,
                    ],
                    'object' => 'chat.completion',
                    'created' => 1776020161,
                ], \JSON_THROW_ON_ERROR);
            }
        };

        $openai = new CompatibleOpenai($client, 'qwen/qwen3.5-plus-20260216');
        $response = $openai->completion(
            messages: [new UserMessage('load recent history')],
            tools: [SearchMessages::class],
        );

        self::assertInstanceOf(CompletionResponse::class, $response);

        $toolCall = $response->choices[0]->message->toolCalls[0] ?? null;

        self::assertInstanceOf(KnownFunctionCall::class, $toolCall);
        self::assertSame(50, $toolCall->arguments->limit);
    }
}
