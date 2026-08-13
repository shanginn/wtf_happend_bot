<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Async\Coroutine;

use function Async\delay;
use function Async\protect;

use function Async\spawn;

use Bot\AgenticWorkflow\ReplyTypingModelGateway;
use Bot\Telegram\TelegramTypingRefresher;
use Closure;
use Phenogram\Bindings\ApiInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PiPHP\AI\Codec\JsonValue;
use PiPHP\AI\Model\ApiProtocol;
use PiPHP\AI\Model\Model;
use PiPHP\AI\Provider\Adapter\OpenAIChatAdapter;
use PiPHP\AI\Transport\HttpRequest;
use PiPHP\AI\Transport\HttpResponse;
use PiPHP\AI\Transport\HttpTransportInterface;
use PiPHP\Temporal\Contract\ModelCompletionGatewayInterface;
use PiPHP\Temporal\DTO\AgentMessage;
use PiPHP\Temporal\DTO\ModelActivityInput;
use PiPHP\Temporal\DTO\ModelActivityResult;
use PiPHP\Temporal\Serialization\PiPayloadCodec;
use RuntimeException;
use Tests\TestCase;
use UnexpectedValueException;

final class ReplyTypingModelGatewayTest extends TestCase
{
    public function testStaySilentDecisionNeverStartsTyping(): void
    {
        $telegram = $this->telegramExpectingNoTyping();
        $gateway  = $this->gateway(
            $telegram,
            delayMilliseconds: 20,
            completion: self::toolResult([[
                'id'        => 'silent-1',
                'name'      => 'stay_silent',
                'arguments' => [],
            ]]),
        );

        $result = $gateway->complete(self::input([
            AgentMessage::text('user', 'ordinary participant chatter')->toArray(),
        ]));

        self::assertSame('stay_silent', $result->toolCalls[0]['name']);
    }

    public function testResearchToolRemainsAvailableBeforeReplyCommitment(): void
    {
        $telegram   = $this->telegramExpectingNoTyping();
        $completion = self::toolResult([[
            'id'        => 'time-1',
            'name'      => 'get_current_time',
            'arguments' => ['timezone' => 'Asia/Yekaterinburg'],
        ]]);
        $gateway = $this->gateway($telegram, delayMilliseconds: 20, completion: $completion);

        $result = $gateway->complete(self::input([
            AgentMessage::text('user', 'what time is it?')->toArray(),
        ]));

        self::assertSame($completion, $result);
    }

    public function testFirstTurnTerminalSendIsReplacedByDeterministicCommitment(): void
    {
        $telegram = $this->telegramExpectingNoTyping();
        $gateway  = $this->gateway(
            $telegram,
            delayMilliseconds: 20,
            completion: self::toolResult([self::telegramSendCall()]),
        );
        $input = self::input([
            AgentMessage::text('user', 'answer this')->toArray(),
        ]);

        $first = $gateway->complete($input);
        $retry = $gateway->complete($input);
        $call  = [
            'id'        => 'reply-commit-' . substr(hash('sha256', 'model-test'), 0, 24),
            'name'      => 'commit_to_reply',
            'arguments' => [],
        ];

        self::assertSame([$call], $first->toolCalls);
        self::assertSame([['type' => 'toolCall', ...$call]], $first->assistantMessage['content']);
        self::assertSame('tool_use', $first->stopReason);
        self::assertSame($first->toolCalls, $retry->toolCalls);
    }

    public function testSyntheticCommitmentPreservesDeepSeekReasoningOnTheNextRequest(): void
    {
        $telegram   = $this->telegramExpectingNoTyping();
        $completion = new ModelActivityResult(
            assistantMessage: [
                'role'    => 'assistant',
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'private reasoning'],
                    ['type' => 'text', 'text' => 'undelivered draft'],
                    ['type' => 'toolCall', ...self::telegramSendCall()],
                ],
            ],
            toolCalls: [self::telegramSendCall()],
            stopReason: 'tool_use',
        );
        $gateway = $this->gateway(
            $telegram,
            delayMilliseconds: 20,
            completion: $completion,
        );

        $result = $gateway->complete(self::input([
            AgentMessage::text('user', 'answer this')->toArray(),
        ]));

        self::assertSame('thinking', $result->assistantMessage['content'][0]['type']);
        self::assertSame(
            'private reasoning',
            $result->assistantMessage['content'][0]['thinking'],
        );
        self::assertSame('toolCall', $result->assistantMessage['content'][1]['type']);
        self::assertSame('commit_to_reply', $result->assistantMessage['content'][1]['name']);
        self::assertCount(2, $result->assistantMessage['content']);

        $model = new Model(
            provider: 'deepseek',
            id: 'deepseek-v4-pro',
            name: 'deepseek-v4-pro',
            api: ApiProtocol::OPENAI_CHAT,
            baseUrl: 'https://api.deepseek.com',
        );
        $context = (new PiPayloadCodec())->context(
            self::input([
                AgentMessage::text('user', 'answer this')->toArray(),
                $result->assistantMessage,
                self::commitmentResult($result->toolCalls[0]['id']),
            ]),
            $model,
        );
        $transport = new CapturingDeepSeekTransport();
        $stream    = (new OpenAIChatAdapter(
            transport: $transport,
            environmentVariable: null,
            authenticationRequired: false,
        ))->stream($model, $context);
        foreach ($stream as $_event);

        $stream->result();

        self::assertCount(1, $transport->requests);
        $body = JsonValue::object(json_decode(
            (string) $transport->requests[0]->body,
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        $messages  = JsonValue::list($body['messages'] ?? null);
        $assistant = JsonValue::object($messages[1] ?? null);
        $tool      = JsonValue::object($messages[2] ?? null);

        self::assertSame('private reasoning', $assistant['reasoning_content'] ?? null);
        self::assertSame('', $assistant['content'] ?? null);
        self::assertSame(
            $result->toolCalls[0]['id'],
            $assistant['tool_calls'][0]['id'] ?? null,
        );
        self::assertSame($result->toolCalls[0]['id'], $tool['tool_call_id'] ?? null);
    }

    public function testCommitAndSendInSameToolBatchIsReducedToCommitOnly(): void
    {
        $telegram = $this->telegramExpectingNoTyping();
        $gateway  = $this->gateway(
            $telegram,
            delayMilliseconds: 20,
            completion: self::toolResult([
                [
                    'id'        => 'provider-commit-1',
                    'name'      => 'commit_to_reply',
                    'arguments' => [],
                ],
                self::telegramSendCall(),
            ]),
        );

        $result = $gateway->complete(self::input([
            AgentMessage::text('user', 'answer this')->toArray(),
        ]));

        self::assertCount(1, $result->toolCalls);
        self::assertSame('commit_to_reply', $result->toolCalls[0]['name']);
        self::assertSame($result->toolCalls[0]['id'], $result->assistantMessage['content'][0]['id']);
    }

    public function testTerminalSendPassesOnlyOnCommittedTurnWhileTyping(): void
    {
        $typingCalls = 0;
        $telegram    = $this->createMock(ApiInterface::class);
        $telegram
            ->expects(self::atLeastOnce())
            ->method('sendChatAction')
            ->willReturnCallback(static function () use (&$typingCalls): bool {
                ++$typingCalls;

                return true;
            });
        $sendResult = self::toolResult([self::telegramSendCall()]);
        $gateway    = $this->gateway(
            $telegram,
            delayMilliseconds: 20,
            refreshMilliseconds: 10,
            completion: $sendResult,
        );

        $first = $gateway->complete(self::input([
            AgentMessage::text('user', 'answer this')->toArray(),
        ]));
        self::assertSame('commit_to_reply', $first->toolCalls[0]['name']);
        self::assertSame(0, $typingCalls);

        $second = $gateway->complete(self::input([
            AgentMessage::text('user', 'answer this')->toArray(),
            self::commitmentResult(),
        ]));

        self::assertSame($sendResult, $second);
        self::assertGreaterThan(0, $typingCalls);
    }

    public function testPlainAssistantReplyIsTurnedIntoCommitmentInsteadOfParentFallback(): void
    {
        $telegram = $this->telegramExpectingNoTyping();
        $gateway  = $this->gateway($telegram, delayMilliseconds: 20);

        $result = $gateway->complete(self::input([
            AgentMessage::text('user', 'answer this')->toArray(),
        ]));

        self::assertSame('commit_to_reply', $result->toolCalls[0]['name']);
        self::assertSame('toolCall', $result->assistantMessage['content'][0]['type']);
        self::assertSame('tool_use', $result->stopReason);
    }

    public function testHistoricalCommitmentDoesNotLeakIntoANewUserTurn(): void
    {
        $telegram = $this->telegramExpectingNoTyping();
        $gateway  = $this->gateway($telegram, delayMilliseconds: 20);

        $gateway->complete(self::input([
            AgentMessage::text('user', 'old question')->toArray(),
            self::commitmentResult(),
            AgentMessage::text('assistant', 'old delivered answer')->toArray(),
            AgentMessage::text('user', 'new ordinary chatter')->toArray(),
        ]));
    }

    public function testCurrentCommitmentRefreshesExactTopicUntilCompletionThenStops(): void
    {
        $calls    = [];
        $telegram = $this->createMock(ApiInterface::class);
        $telegram
            ->expects(self::atLeast(2))
            ->method('sendChatAction')
            ->willReturnCallback(static function (
                int|string $chatId,
                string $action,
                ?string $businessConnectionId,
                ?int $messageThreadId,
            ) use (&$calls): bool {
                $calls[] = [$chatId, $action, $businessConnectionId, $messageThreadId];

                return true;
            });

        $gateway = $this->gateway($telegram, delayMilliseconds: 35, refreshMilliseconds: 10);
        $gateway->complete(self::input([
            AgentMessage::text('user', 'answer this')->toArray(),
            self::commitmentResult(),
        ], chatId: -100123, topicId: 77));

        self::assertGreaterThanOrEqual(3, count($calls));
        foreach ($calls as $call) {
            self::assertSame([-100123, 'typing', null, 77], $call);
        }

        $countAtCompletion = count($calls);
        delay(25);
        self::assertCount($countAtCompletion, $calls, 'No typing pulse may race after model completion.');
    }

    public function testResolvedSpaceCommandStartsTypingWithoutCommitmentToolHistory(): void
    {
        $calls    = [];
        $telegram = $this->createMock(ApiInterface::class);
        $telegram
            ->expects(self::atLeastOnce())
            ->method('sendChatAction')
            ->willReturnCallback(static function (
                int|string $chatId,
                string $action,
                ?string $businessConnectionId,
                ?int $messageThreadId,
            ) use (&$calls): bool {
                $calls[] = [$chatId, $action, $businessConnectionId, $messageThreadId];

                return true;
            });

        $gateway = $this->gateway($telegram, delayMilliseconds: 20, refreshMilliseconds: 10);
        $gateway->complete(self::input(
            [AgentMessage::text('user', '/dimannews')->toArray()],
            chatId: -100456,
            topicId: null,
            extraMetadata: ['spaceCommand' => 'dimannews'],
        ));

        self::assertNotEmpty($calls);
        foreach ($calls as $call) {
            self::assertSame([-100456, 'typing', null, null], $call);
        }
    }

    public function testTypingFailuresNeverBlockModelCompletion(): void
    {
        $telegram = $this->createMock(ApiInterface::class);
        $telegram
            ->expects(self::atLeastOnce())
            ->method('sendChatAction')
            ->willThrowException(new RuntimeException('Telegram is unavailable'));
        $gateway = $this->gateway($telegram, delayMilliseconds: 20, refreshMilliseconds: 5);

        $result = $gateway->complete(self::input([
            AgentMessage::text('user', 'answer this')->toArray(),
            self::commitmentResult(),
        ]));

        self::assertSame('stop', $result->stopReason);
        self::assertSame('done', $result->assistantMessage['content'][0]['text']);
    }

    public function testUncancellablePendingTypingRequestDoesNotDelayModelCompletion(): void
    {
        $calls    = 0;
        $telegram = $this->createMock(ApiInterface::class);
        $telegram
            ->expects(self::once())
            ->method('sendChatAction')
            ->willReturnCallback(static function () use (&$calls): bool {
                ++$calls;
                protect(static function (): void {
                    delay(200);
                });

                return true;
            });
        $gateway = $this->gateway($telegram, delayMilliseconds: 10, refreshMilliseconds: 5);
        $started = hrtime(true);

        $result = $gateway->complete(self::input([
            AgentMessage::text('user', 'answer this')->toArray(),
            self::commitmentResult(),
        ]));
        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;

        self::assertSame('done', $result->assistantMessage['content'][0]['text']);
        self::assertLessThan(100, $elapsedMilliseconds);
        self::assertSame(1, $calls);

        // Let the protected fake request unwind. The cancellation requested
        // above must prevent a later refresh from starting after the reply.
        delay(220);
        self::assertSame(1, $calls);
    }

    public function testCommittedReplyWithMalformedRouteFailsBeforeModelCompletion(): void
    {
        $telegram = $this->telegramExpectingNoTyping();
        $calls    = 0;
        $gateway  = new ReplyTypingModelGateway(
            inner: new CallbackModelGateway(static function () use (&$calls): ModelActivityResult {
                ++$calls;

                return self::modelResult();
            }),
            typing: new TelegramTypingRefresher($telegram, 10),
        );

        try {
            $gateway->complete(self::input(
                [
                    AgentMessage::text('user', 'answer this')->toArray(),
                    self::commitmentResult(),
                ],
                extraMetadata: ['chatId' => '-100123', 'topicId' => 77],
            ));
            self::fail('A committed reply with an invalid route must fail closed.');
        } catch (UnexpectedValueException $exception) {
            self::assertSame(
                'A committed Telegram reply requires a valid integer chat and topic route.',
                $exception->getMessage(),
            );
        }

        self::assertSame(0, $calls);
    }

    public function testResolvedCommandWithMalformedRouteFailsBeforeModelCompletion(): void
    {
        $telegram = $this->telegramExpectingNoTyping();
        $calls    = 0;
        $gateway  = new ReplyTypingModelGateway(
            inner: new CallbackModelGateway(static function () use (&$calls): ModelActivityResult {
                ++$calls;

                return self::modelResult();
            }),
            typing: new TelegramTypingRefresher($telegram, 10),
        );

        try {
            $gateway->complete(self::input(
                [AgentMessage::text('user', '/dimannews')->toArray()],
                extraMetadata: [
                    'chatId'       => -100123,
                    'topicId'      => '77',
                    'spaceCommand' => 'dimannews',
                ],
            ));
            self::fail('A resolved command with an invalid route must fail closed.');
        } catch (UnexpectedValueException $exception) {
            self::assertSame(
                'A committed Telegram reply requires a valid integer chat and topic route.',
                $exception->getMessage(),
            );
        }

        self::assertSame(0, $calls);
    }

    /** @return array<string, mixed> */
    private static function commitmentResult(string $toolCallId = 'commit-1'): array
    {
        return [
            'role'       => 'toolResult',
            'toolName'   => 'commit_to_reply',
            'toolCallId' => $toolCallId,
            'content'    => [['type' => 'text', 'text' => 'Reply commitment accepted.']],
            'isError'    => false,
        ];
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $extraMetadata
     * @param int                        $chatId
     * @param ?int                       $topicId
     */
    private static function input(
        array $messages,
        int $chatId = -100123,
        ?int $topicId = 77,
        array $extraMetadata = [],
    ): ModelActivityInput {
        return new ModelActivityInput(
            model: 'test-model',
            messages: $messages,
            tools: [],
            metadata: [
                'chatId'  => $chatId,
                'topicId' => $topicId,
                ...$extraMetadata,
            ],
            idempotencyKey: 'model-test',
        );
    }

    private static function modelResult(): ModelActivityResult
    {
        return new ModelActivityResult(
            assistantMessage: AgentMessage::text('assistant', 'done')->toArray(),
            toolCalls: [],
            stopReason: 'stop',
        );
    }

    /**
     * @param list<array{id: string, name: string, arguments: array<string, mixed>}> $calls
     */
    private static function toolResult(array $calls): ModelActivityResult
    {
        return new ModelActivityResult(
            assistantMessage: [
                'role'    => 'assistant',
                'content' => array_map(
                    static fn (array $call): array => ['type' => 'toolCall', ...$call],
                    $calls,
                ),
            ],
            toolCalls: $calls,
            stopReason: 'tool_use',
        );
    }

    /** @return array{id: string, name: string, arguments: array<string, mixed>} */
    private static function telegramSendCall(): array
    {
        return [
            'id'        => 'send-1',
            'name'      => 'telegram_api_call',
            'arguments' => [
                'method'     => 'sendMessage',
                'parameters' => ['text' => 'done'],
            ],
        ];
    }

    /** @return ApiInterface&MockObject */
    private function telegramExpectingNoTyping(): ApiInterface
    {
        $telegram = $this->createMock(ApiInterface::class);
        $telegram->expects(self::never())->method('sendChatAction');

        return $telegram;
    }

    private function gateway(
        ApiInterface $telegram,
        int $delayMilliseconds,
        int $refreshMilliseconds = 10,
        ?ModelActivityResult $completion = null,
    ): ReplyTypingModelGateway {
        $completion ??= self::modelResult();

        return new ReplyTypingModelGateway(
            inner: new CallbackModelGateway(static function (
                ModelActivityInput $_input,
            ) use ($completion, $delayMilliseconds): ModelActivityResult {
                delay($delayMilliseconds);

                return $completion;
            }),
            typing: new TelegramTypingRefresher($telegram, $refreshMilliseconds),
        );
    }
}

final readonly class CallbackModelGateway implements ModelCompletionGatewayInterface
{
    /** @param Closure(ModelActivityInput): ModelActivityResult $callback */
    public function __construct(private Closure $callback) {}

    public function complete(ModelActivityInput $input): ModelActivityResult
    {
        return ($this->callback)($input);
    }
}

final class CapturingDeepSeekTransport implements HttpTransportInterface
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    public function request(HttpRequest $request): Coroutine
    {
        $this->requests[] = $request;

        return spawn(static fn (): HttpResponse => new HttpResponse(status: 200));
    }

    public function stream(HttpRequest $request, Closure $onChunk): Coroutine
    {
        $this->requests[] = $request;

        return spawn(static function () use ($onChunk): HttpResponse {
            $onChunk("data: {\"choices\":[{\"delta\":{\"content\":\"ok\"},\"finish_reason\":\"stop\"}]}\n\n");
            $onChunk("data: [DONE]\n\n");

            return new HttpResponse(status: 200);
        });
    }
}
