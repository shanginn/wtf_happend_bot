<?php

declare(strict_types=1);

namespace Tests\Openai;

use Bot\Llm\Tools\Chat\SearchMessages;
use Bot\Openai\CompatibleOpenai;
use Bot\Openai\CompatibleOpenaiSerializer;
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
