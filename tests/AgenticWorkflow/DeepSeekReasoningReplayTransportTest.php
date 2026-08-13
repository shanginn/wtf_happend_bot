<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Async\Coroutine;

use function Async\await;
use function Async\spawn;

use Bot\AgenticWorkflow\DeepSeekReasoningReplayTransport;
use Closure;
use PHPUnit\Framework\TestCase;
use PiPHP\AI\Model\ApiProtocol;
use PiPHP\AI\Model\Model;
use PiPHP\AI\Provider\Adapter\OpenAIChatAdapter;
use PiPHP\AI\Transport\HttpRequest;
use PiPHP\AI\Transport\HttpResponse;
use PiPHP\AI\Transport\HttpTransportInterface;
use PiPHP\Temporal\DTO\AgentMessage;
use PiPHP\Temporal\DTO\ModelActivityInput;
use PiPHP\Temporal\Serialization\PiPayloadCodec;
use stdClass;

final class DeepSeekReasoningReplayTransportTest extends TestCase
{
    public function testAddsEmptyReasoningToZeroReasoningAssistantToolTurn(): void
    {
        $inner     = new CapturingReasoningReplayTransport();
        $transport = new DeepSeekReasoningReplayTransport($inner);
        $request   = new HttpRequest(
            method: 'POST',
            url: 'https://api.deepseek.com/chat/completions',
            headers: ['content-type' => 'application/json'],
            body: json_encode([
                'model'    => 'deepseek-v4-pro',
                'messages' => [
                    ['role' => 'user', 'content' => 'request'],
                    [
                        'role'       => 'assistant',
                        'content'    => '',
                        'tool_calls' => [[
                            'id'       => 'call_commit',
                            'type'     => 'function',
                            'function' => [
                                'name'      => 'commit_to_reply',
                                'arguments' => '{}',
                            ],
                        ]],
                    ],
                    ['role' => 'tool', 'tool_call_id' => 'call_commit', 'content' => 'accepted'],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        await($transport->stream($request, static function (string $_chunk): void {}));

        self::assertCount(1, $inner->requests);
        $payload = json_decode(
            (string) $inner->requests[0]->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertArrayHasKey('reasoning_content', $payload['messages'][1]);
        self::assertSame('', $payload['messages'][1]['reasoning_content']);
        self::assertSame('call_commit', $payload['messages'][1]['tool_calls'][0]['id']);
    }

    public function testPreservesNonEmptyReasoningAndUnrelatedMessagesExactly(): void
    {
        $inner     = new CapturingReasoningReplayTransport();
        $transport = new DeepSeekReasoningReplayTransport($inner);
        $messages  = [
            ['role' => 'assistant', 'content' => 'ordinary answer'],
            [
                'role'              => 'assistant',
                'content'           => '',
                'reasoning_content' => 'provider reasoning',
                'tool_calls'        => [[
                    'id'       => 'call_existing',
                    'type'     => 'function',
                    'function' => ['name' => 'save_memory', 'arguments' => '{}'],
                ]],
            ],
        ];

        await($transport->request(new HttpRequest(
            method: 'POST',
            url: 'https://api.deepseek.com/chat/completions',
            body: json_encode(['messages' => $messages], JSON_THROW_ON_ERROR),
        )));

        $payload = json_decode(
            (string) $inner->requests[0]->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame($messages, $payload['messages']);
    }

    public function testPreservesEmptyJsonSchemaObjects(): void
    {
        $inner     = new CapturingReasoningReplayTransport();
        $transport = new DeepSeekReasoningReplayTransport($inner);

        await($transport->request(new HttpRequest(
            method: 'POST',
            url: 'https://api.deepseek.com/chat/completions',
            body: <<<'JSON'
                {
                    "messages": [{"role": "user", "content": "request"}],
                    "tools": [{
                        "type": "function",
                        "function": {
                            "name": "commit_to_reply",
                            "description": "Commit to a visible reply.",
                            "parameters": {
                                "type": "object",
                                "properties": {},
                                "additionalProperties": false
                            }
                        }
                    }]
                }
                JSON,
        )));

        $payload = json_decode(
            (string) $inner->requests[0]->body,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(stdClass::class, $payload);
        self::assertInstanceOf(stdClass::class, $payload->tools[0]->function->parameters->properties);
    }

    public function testDoesNotExpandUnicodeOrEscapeUrlsWhenReencoding(): void
    {
        $inner     = new CapturingReasoningReplayTransport();
        $transport = new DeepSeekReasoningReplayTransport($inner);

        await($transport->request(new HttpRequest(
            method: 'POST',
            url: 'https://api.deepseek.com/chat/completions',
            body: <<<'JSON'
                {"messages":[{"role":"user","content":"Привет https://example.com/a/b"}]}
                JSON,
        )));

        $body = (string) $inner->requests[0]->body;
        self::assertStringContainsString('Привет', $body);
        self::assertStringContainsString('https://example.com/a/b', $body);
        self::assertStringNotContainsString('\\u', $body);
        self::assertStringNotContainsString('https:\\/\\/', $body);
    }

    public function testRealAdapterReplaysEmptyReasoningForSyntheticCommitment(): void
    {
        $inner   = new CapturingReasoningReplayTransport();
        $adapter = new OpenAIChatAdapter(
            transport: new DeepSeekReasoningReplayTransport($inner),
            environmentVariable: null,
            authenticationRequired: false,
        );
        $model = new Model(
            provider: 'deepseek',
            id: 'deepseek-v4-pro',
            name: 'deepseek-v4-pro',
            api: ApiProtocol::OPENAI_CHAT,
            baseUrl: 'https://api.deepseek.com',
        );
        $context = (new PiPayloadCodec())->context(new ModelActivityInput(
            model: 'deepseek/deepseek-v4-pro',
            messages: [
                AgentMessage::text('user', 'request')->toArray(),
                [
                    'role'    => 'assistant',
                    'content' => [[
                        'type'      => 'toolCall',
                        'id'        => 'reply-commit-zero-reasoning',
                        'name'      => 'commit_to_reply',
                        'arguments' => [],
                    ]],
                ],
                [
                    'role'       => 'toolResult',
                    'toolCallId' => 'reply-commit-zero-reasoning',
                    'toolName'   => 'commit_to_reply',
                    'content'    => [['type' => 'text', 'text' => 'accepted']],
                    'isError'    => false,
                ],
            ],
            tools: [],
            metadata: [],
            idempotencyKey: 'zero-reasoning-regression',
        ), $model);

        $stream = $adapter->stream($model, $context);
        foreach ($stream as $_event);
        $stream->result();

        $payload = json_decode(
            (string) $inner->requests[0]->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('', $payload['messages'][1]['reasoning_content'] ?? null);
        self::assertSame(
            'reply-commit-zero-reasoning',
            $payload['messages'][1]['tool_calls'][0]['id'] ?? null,
        );
        self::assertSame(
            'reply-commit-zero-reasoning',
            $payload['messages'][2]['tool_call_id'] ?? null,
        );
    }
}

final class CapturingReasoningReplayTransport implements HttpTransportInterface
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    public function request(HttpRequest $request): Coroutine
    {
        $this->requests[] = $request;

        return spawn(static fn (): HttpResponse => new HttpResponse(
            status: 200,
            headers: [],
            body: '',
        ));
    }

    public function stream(HttpRequest $request, Closure $onChunk): Coroutine
    {
        $this->requests[] = $request;

        return spawn(static function () use ($onChunk): HttpResponse {
            $onChunk("data: {\"choices\":[{\"delta\":{\"content\":\"ok\"},\"finish_reason\":\"stop\"}]}\n\n");
            $onChunk("data: [DONE]\n\n");

            return new HttpResponse(
                status: 200,
                headers: [],
                body: '',
            );
        });
    }
}
