<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\AgenticWorkflow;
use Bot\AgenticWorkflow\AgenticWorkflowInput;
use Bot\AgenticWorkflow\MessageQueue;
use Bot\AgenticWorkflow\QueuedTelegramUpdate;
use Bot\Telegram\Update;
use Bot\Temporal\AgenticWorkflowInputDataConverter;
use InvalidArgumentException;
use LogicException;
use Phenogram\Bindings\Types\CallbackQuery;
use Phenogram\Bindings\Types\Chat;
use Phenogram\Bindings\Types\Message;
use Phenogram\Bindings\Types\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PiPHP\Temporal\DTO\AgentSnapshot;
use PiPHP\Temporal\DTO\AgentWorkflowInput;
use PiPHP\Temporal\DTO\AgentWorkflowResult;
use PiPHP\Temporal\Enum\AgentWorkflowStatus;
use PiPHP\Temporal\Serialization\HistoryPayloadGuard;
use PiPHP\Temporal\Workflow\DurableAgentWorkflow;
use ReflectionClass;
use ReflectionMethod;
use Temporal\DataConverter\Type;
use Temporal\Exception\Failure\ApplicationFailure;
use Tests\TestCase;

final class AgenticWorkflowStateTest extends TestCase
{
    /**
     * @return iterable<string, array{pipelinePendingSince: int, callbackPending: bool, expected: bool}>
     */
    public static function immediateAgentRunCases(): iterable
    {
        yield 'callback batch' => [100, true, true];
        yield 'ordinary batch' => [100, false, false];
        yield 'no pending batch' => [0, true, false];
    }

    /**
     * @return iterable<string, array{actors: array<int, int>, pendingCount: int, complete: bool}>
     */
    public static function invalidPendingActorState(): iterable
    {
        yield 'unsorted actors' => [
            'actors'       => [12, 7],
            'pendingCount' => 1,
            'complete'     => true,
        ];
        yield 'duplicate actors' => [
            'actors'       => [7, 7],
            'pendingCount' => 1,
            'complete'     => true,
        ];
        yield 'incomplete identity without a batch' => [
            'actors'       => [],
            'pendingCount' => 0,
            'complete'     => false,
        ];
    }

    public function testTelegramHistoryAnchorSurvivesContinueAsNew(): void
    {
        $input = new AgenticWorkflowInput(
            chatId: 1,
            chatType: 'private',
            model: 'test/model',
            tools: [],
            messages: [
                [
                    'role'     => 'user',
                    'content'  => [['type' => 'text', 'text' => 'old turn']],
                    'metadata' => ['telegramMessageTimestamp' => 1_700_000_000],
                ],
                [
                    'role'     => 'user',
                    'content'  => [['type' => 'text', 'text' => 'first current update']],
                    'metadata' => ['telegramMessageTimestamp' => 1_710_000_000],
                ],
                [
                    'role'     => 'user',
                    'content'  => [['type' => 'text', 'text' => 'last current update']],
                    'metadata' => ['telegramMessageTimestamp' => 1_710_000_321],
                ],
            ],
            pipelinePendingSince: 1_799_999_999,
            pendingBatchMessageCount: 2,
            pendingActorUserIds: [7],
        );

        $converter = new AgenticWorkflowInputDataConverter();
        $payload   = $converter->toPayload($input);
        self::assertNotNull($payload);
        $continued = $converter->fromPayload(
            $payload,
            Type::create(AgenticWorkflowInput::class),
        );

        $anchor = (new ReflectionMethod(
            AgenticWorkflow::class,
            'pendingBatchHistoryReferenceTimestamp',
        ))->invoke(
            null,
            $continued->messages,
            $continued->pendingBatchMessageCount,
        );

        self::assertSame(1_710_000_321, $anchor);
        self::assertNotSame($continued->pipelinePendingSince, $anchor);
    }

    #[DataProvider('immediateAgentRunCases')]
    public function testCallbackBatchRunsImmediately(
        int $pipelinePendingSince,
        bool $callbackPending,
        bool $expected,
    ): void {
        $reflection = new ReflectionClass(AgenticWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('pipelinePendingSince')->setValue($workflow, $pipelinePendingSince);
        $reflection->getProperty('callbackPending')->setValue($workflow, $callbackPending);

        $method = new ReflectionMethod(AgenticWorkflow::class, 'shouldRunAgentImmediately');

        self::assertSame($expected, $method->invoke($workflow));
    }

    public function testIngestionSnapshotIsOrderedBeforeFirstEligibleCallbackBoundary(): void
    {
        $callback = static fn (string $id): CallbackQuery => new CallbackQuery(
            id: $id,
            from: new User(id: 7, isBot: false, firstName: 'Alice'),
            chatInstance: 'chat-1',
            data: 'action',
        );
        $updates = [
            new QueuedTelegramUpdate(new Update(updateId: 30), true, 'ingestion-d'),
            new QueuedTelegramUpdate(
                new Update(updateId: 20, callbackQuery: $callback('callback-c')),
                true,
                'ingestion-c',
            ),
            new QueuedTelegramUpdate(new Update(updateId: 20), true, 'ingestion-b'),
            new QueuedTelegramUpdate(new Update(updateId: 10), true, 'ingestion-a'),
            new QueuedTelegramUpdate(
                new Update(updateId: 5, callbackQuery: $callback('paused-callback')),
                false,
                'ingestion-paused',
            ),
        ];

        $method              = new ReflectionMethod(AgenticWorkflow::class, 'nextIngestionBatch');
        [$batch, $remainder] = $method->invoke(null, $updates);

        self::assertSame(
            ['ingestion-paused', 'ingestion-a', 'ingestion-b', 'ingestion-c'],
            array_map(
                static fn (QueuedTelegramUpdate $queued): string => $queued->ingestionId,
                $batch,
            ),
        );
        self::assertSame(
            ['ingestion-d'],
            array_map(
                static fn (QueuedTelegramUpdate $queued): string => $queued->ingestionId,
                $remainder,
            ),
        );
    }

    public function testAgentInputDropsOldestPriorTurnsButRetainsCurrentBatch(): void
    {
        $system  = self::message('system', 'system prompt');
        $oldest  = self::message('user', str_repeat('a', 800_000));
        $recent  = self::message('user', str_repeat('b', 800_000));
        $current = self::message('user', 'current Telegram batch');
        $tools   = [[
            'name'        => 'stay_silent',
            'description' => 'Finish without sending a message.',
            'parameters'  => ['type' => 'object', 'properties' => []],
        ]];

        $method = new ReflectionMethod(AgenticWorkflow::class, 'boundedAgentInput');
        $input  = $method->invoke(
            null,
            'telegram-chat-1',
            'test/model',
            [$system, $oldest, $recent, $current],
            $tools,
            ['chatId' => 1],
            1,
        );

        self::assertInstanceOf(AgentWorkflowInput::class, $input);
        self::assertSame([$system, $recent], array_slice($input->messages, 0, 2));
        self::assertSame('user', $input->messages[2]['role'] ?? null);
        self::assertTrue($input->messages[2]['metadata']['telegramBatch'] ?? false);
        self::assertSame(
            'current Telegram batch',
            $input->messages[2]['content'][2]['text'] ?? null,
        );
        self::assertSame($tools, $input->tools);
    }

    public function testTwoHundredMessageCanBatchRemainsOneIndivisibleMultiTurnToolHistory(): void
    {
        $system       = self::message('system', 'system prompt');
        $currentBatch = [];
        for ($index = 1; $index <= 200; ++$index) {
            $currentBatch[] = [
                'role'    => 'user',
                'content' => [[
                    'type' => 'text',
                    'text' => "message {$index}\nParticipant reference: telegram_user:{$index}",
                ]],
                'name'     => "telegram_user:{$index}",
                'metadata' => ['telegramParticipant' => "telegram_user:{$index}"],
            ];
        }

        // This round-trip is the parent continue-as-new boundary after the
        // first 100 updates and before the next 100 are dispatched.
        $parentInput = new AgenticWorkflowInput(
            chatId: 1,
            chatType: 'private',
            model: 'test/model',
            tools: [],
            messages: [$system, ...$currentBatch],
            pendingBatchMessageCount: 200,
            pendingActorIdentityComplete: false,
        );
        $converter = new AgenticWorkflowInputDataConverter();
        $payload   = $converter->toPayload($parentInput);
        self::assertNotNull($payload);
        $continued = $converter->fromPayload(
            $payload,
            Type::create(AgenticWorkflowInput::class),
        );
        self::assertCount(201, $continued->messages);
        self::assertSame(200, $continued->pendingBatchMessageCount);

        $childInput = (new ReflectionMethod(
            AgenticWorkflow::class,
            'boundedAgentInput',
        ))->invoke(
            null,
            'telegram-chat-1',
            'test/model',
            $continued->messages,
            [],
            ['chatId' => 1],
            $continued->pendingBatchMessageCount,
        );
        self::assertInstanceOf(AgentWorkflowInput::class, $childInput);
        self::assertCount(2, $childInput->messages);

        $composite = $childInput->messages[1];
        self::assertSame(200, $composite['metadata']['telegramBatchMessageCount'] ?? null);
        self::assertContains(
            'message 1' . "\n" . 'Participant reference: telegram_user:1',
            array_column($composite['content'], 'text'),
        );
        self::assertContains(
            'message 200' . "\n" . 'Participant reference: telegram_user:200',
            array_column($composite['content'], 'text'),
        );

        $multiTurnHistory = [
            ...$childInput->messages,
            [
                'role'    => 'assistant',
                'content' => [[
                    'type'      => 'toolCall',
                    'id'        => 'search-1',
                    'name'      => 'search_messages',
                    'arguments' => ['query' => 'context'],
                ]],
            ],
            [
                'role'       => 'toolResult',
                'toolName'   => 'search_messages',
                'toolCallId' => 'search-1',
                'content'    => [['type' => 'text', 'text' => 'first result']],
            ],
            [
                'role'    => 'assistant',
                'content' => [[
                    'type'      => 'toolCall',
                    'id'        => 'time-2',
                    'name'      => 'get_current_time',
                    'arguments' => ['timezone' => 'Asia/Yekaterinburg'],
                ]],
            ],
            [
                'role'       => 'toolResult',
                'toolName'   => 'get_current_time',
                'toolCallId' => 'time-2',
                'content'    => [['type' => 'text', 'text' => 'second result']],
            ],
            self::message('assistant', 'final answer'),
        ];

        $durableWorkflow = new DurableAgentWorkflow();
        $trimmed         = (new ReflectionMethod(
            DurableAgentWorkflow::class,
            'trimMessages',
        ))->invoke($durableWorkflow, $multiTurnHistory, 4);

        // The count limit is soft for the one indivisible user turn: PiPH
        // retains the complete 200-update composite and every later tool turn.
        self::assertSame($multiTurnHistory, $trimmed);
    }

    public function testPendingActorMetadataUsesRealUsersAndFailsClosedForAnonymousSenders(): void
    {
        $reflection = new ReflectionClass(AgenticWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('pendingActorUserIds')->setValue($workflow, []);
        $reflection->getProperty('pendingActorIdentityComplete')->setValue($workflow, true);

        $track = new ReflectionMethod(AgenticWorkflow::class, 'trackPendingActor');
        $track->invoke($workflow, new Update(
            updateId: 10,
            message: new Message(
                messageId: 20,
                date: 100,
                chat: new Chat(id: -100123, type: 'supergroup'),
                from: new User(id: 7, isBot: false, firstName: 'Alice'),
                text: 'normal sender',
            ),
        ));
        $track->invoke($workflow, new Update(
            updateId: 11,
            message: new Message(
                messageId: 21,
                date: 101,
                chat: new Chat(id: -100123, type: 'supergroup'),
                from: new User(id: 1087968824, isBot: true, firstName: 'GroupAnonymousBot'),
                senderChat: new Chat(id: -100123, type: 'supergroup'),
                text: 'anonymous admin',
            ),
        ));

        $actorIds = new ReflectionMethod(AgenticWorkflow::class, 'pendingActorIds');

        self::assertSame([7], $actorIds->invoke($workflow));
        self::assertFalse(
            $reflection->getProperty('pendingActorIdentityComplete')->getValue($workflow),
        );
    }

    public function testActorMetadataSurvivesParentAndChildContinuationThenResetsPerBatch(): void
    {
        $system  = self::message('system', 'system prompt');
        $current = self::message('user', 'current Telegram batch');
        $tools   = [[
            'name'        => 'stay_silent',
            'description' => 'Finish without sending a message.',
            'parameters'  => ['type' => 'object', 'properties' => []],
        ]];
        $reflection = new ReflectionClass(AgenticWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('input')->setValue($workflow, new AgenticWorkflowInput(
            chatId: -100123,
            chatType: 'supergroup',
            model: 'test/model',
            tools: $tools,
        ));
        $reflection->getProperty('updatesQueue')->setValue($workflow, new MessageQueue());
        $reflection->getProperty('messages')->setValue($workflow, [$system, $current]);
        $reflection->getProperty('pendingBatchMessageCount')->setValue($workflow, 1);
        $reflection->getProperty('pendingActorUserIds')->setValue(
            $workflow,
            [12 => true, 7 => true],
        );
        $reflection->getProperty('pendingActorIdentityComplete')->setValue($workflow, false);
        $reflection->getProperty('pipelinePendingSince')->setValue($workflow, 123);
        $reflection->getProperty('callbackPending')->setValue($workflow, true);

        $continuation = (new ReflectionMethod(
            AgenticWorkflow::class,
            'continuationInput',
        ))->invoke($workflow, [$system, $current]);

        self::assertInstanceOf(AgenticWorkflowInput::class, $continuation);
        self::assertSame('supergroup', $continuation->chatType);
        self::assertSame([7, 12], $continuation->pendingActorUserIds);
        self::assertFalse($continuation->pendingActorIdentityComplete);

        $metadata = [
            'chatId'                => $continuation->chatId,
            'chatType'              => $continuation->chatType,
            'actorUserIds'          => $continuation->pendingActorUserIds,
            'actorIdentityComplete' => $continuation->pendingActorIdentityComplete,
        ];
        $childInput = (new ReflectionMethod(
            AgenticWorkflow::class,
            'boundedAgentInput',
        ))->invoke(
            null,
            'telegram-chat--100123',
            'test/model',
            [$system, $current],
            $tools,
            $metadata,
            1,
        );
        self::assertInstanceOf(AgentWorkflowInput::class, $childInput);
        self::assertSame($metadata, $childInput->metadata);

        $continuedChild = $childInput->continued(
            messages: $childInput->messages,
            tools: $childInput->tools,
            pendingSteering: [],
            pendingFollowUps: [],
            completedTurns: 1,
            usage: [],
        );
        self::assertSame($metadata, $continuedChild->metadata);

        (new ReflectionMethod(AgenticWorkflow::class, 'finishPipeline'))->invoke($workflow);

        self::assertSame(
            [],
            (new ReflectionMethod(AgenticWorkflow::class, 'pendingActorIds'))->invoke($workflow),
        );
        self::assertTrue(
            $reflection->getProperty('pendingActorIdentityComplete')->getValue($workflow),
        );
        self::assertSame(
            0,
            $reflection->getProperty('pendingBatchMessageCount')->getValue($workflow),
        );
        self::assertSame(0, $reflection->getProperty('pipelinePendingSince')->getValue($workflow));
        self::assertFalse($reflection->getProperty('callbackPending')->getValue($workflow));
    }

    /**
     * @param list<int> $actors
     */
    #[DataProvider('invalidPendingActorState')]
    public function testInputRejectsNonCanonicalPendingActorState(
        array $actors,
        int $pendingCount,
        bool $complete,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new AgenticWorkflowInput(
            chatId: -100123,
            chatType: 'supergroup',
            model: 'test/model',
            tools: [],
            messages: $pendingCount === 0 ? [] : [self::message('user', 'pending')],
            pendingBatchMessageCount: $pendingCount,
            pendingActorUserIds: $actors,
            pendingActorIdentityComplete: $complete,
        );
    }

    public function testMandatoryCurrentBatchBudgetFailureIsExplicitAndNonRetryable(): void
    {
        $system  = self::message('system', 'system prompt');
        $current = self::message(
            'user',
            str_repeat('x', HistoryPayloadGuard::MAX_ENCODED_BYTES - 20_000),
        );
        $tools = [[
            'name'        => 'large_tool',
            'description' => str_repeat('t', 30_000),
            'parameters'  => ['type' => 'object', 'properties' => []],
        ]];
        self::assertLessThan(
            HistoryPayloadGuard::MAX_ENCODED_BYTES,
            HistoryPayloadGuard::encodedBytes([$system, $current]),
        );

        $method = new ReflectionMethod(AgenticWorkflow::class, 'boundedAgentInput');

        try {
            $method->invoke(
                null,
                'telegram-chat-1',
                'test/model',
                [$system, $current],
                $tools,
                ['chatId' => 1],
                1,
            );
            self::fail('An oversized mandatory agent input must fail the workflow explicitly.');
        } catch (ApplicationFailure $failure) {
            self::assertTrue($failure->isNonRetryable());
            self::assertSame('agent-workflow-input-too-large', $failure->getType());
            self::assertStringContainsString(
                (string) HistoryPayloadGuard::MAX_ENCODED_BYTES,
                $failure->getOriginalMessage(),
            );
        }
    }

    public function testContinuationPayloadIsBoundedAfterContextFailure(): void
    {
        $system  = self::message('system', 'system prompt');
        $oldest  = self::message('user', str_repeat('a', 800_000));
        $recent  = self::message('user', str_repeat('b', 800_000));
        $current = self::message('user', 'current Telegram batch');
        $tools   = [[
            'name'        => 'stay_silent',
            'description' => 'Finish without sending a message.',
            'parameters'  => ['type' => 'object', 'properties' => []],
        ]];
        $reflection = new ReflectionClass(AgenticWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('input')->setValue($workflow, new AgenticWorkflowInput(
            chatId: 1,
            chatType: 'private',
            model: 'test/model',
            tools: $tools,
        ));
        $reflection->getProperty('updatesQueue')->setValue($workflow, new MessageQueue());
        $reflection->getProperty('messages')->setValue(
            $workflow,
            [$system, $oldest, $recent, $current],
        );
        $reflection->getProperty('pendingBatchMessageCount')->setValue($workflow, 1);
        $reflection->getProperty('pendingActorUserIds')->setValue($workflow, [1 => true]);
        $reflection->getProperty('pendingActorIdentityComplete')->setValue($workflow, true);
        $reflection->getProperty('pipelinePendingSince')->setValue($workflow, 123);
        $reflection->getProperty('contextFailureCount')->setValue($workflow, 2);

        $method = new ReflectionMethod(AgenticWorkflow::class, 'boundedContinuationInput');
        $input  = $method->invoke($workflow);

        self::assertInstanceOf(AgenticWorkflowInput::class, $input);
        self::assertSame([$system, $recent, $current], $input->messages);
        self::assertSame(1, $input->pendingBatchMessageCount);
        self::assertSame(123, $input->pipelinePendingSince);
        self::assertSame(2, $input->contextFailureCount);
        self::assertLessThanOrEqual(
            HistoryPayloadGuard::MAX_ENCODED_BYTES,
            (new AgenticWorkflowInputDataConverter())->encodedBytes($input),
        );
    }

    public function testCompletedResultRecoversAssistantTextWhenFinalTextBudgetOmittedIt(): void
    {
        $snapshot = new AgentSnapshot(
            agentId: 'telegram-chat-1',
            workflowId: 'child-workflow-1',
            runId: 'child-run-1',
            status: AgentWorkflowStatus::Completed,
            completedTurns: 1,
            maxTurns: 32,
            continuation: 0,
            messages: [
                self::message('system', 'system prompt'),
                self::message('user', 'question'),
                self::message('assistant', 'answer retained only in the snapshot'),
            ],
            pendingSteering: [],
            pendingFollowUps: [],
            usage: [],
            stopRequested: false,
            stopReason: null,
        );
        $result = new AgentWorkflowResult(
            status: AgentWorkflowStatus::Completed,
            snapshot: $snapshot,
            finalText: null,
        );

        $text = (new ReflectionMethod(
            AgenticWorkflow::class,
            'completedResultText',
        ))->invoke(null, $result);

        self::assertSame('answer retained only in the snapshot', $text);
    }

    public function testConfirmedChildTerminalToolPreventsSnapshotTextFallbackDuplicate(): void
    {
        $messages = [
            self::message('system', 'system prompt'),
            self::message('user', 'question'),
            [
                'role'    => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'text that must not be sent twice'],
                    [
                        'type'      => 'toolCall',
                        'id'        => 'telegram-send-1',
                        'name'      => 'telegram_api_call',
                        'arguments' => [
                            'method'     => 'sendMessage',
                            'parameters' => ['text' => 'already sent'],
                        ],
                    ],
                ],
            ],
            [
                'role'       => 'toolResult',
                'toolName'   => 'telegram_api_call',
                'toolCallId' => 'telegram-send-1',
                'content'    => [['type' => 'text', 'text' => 'sent']],
                'isError'    => false,
                'metadata'   => ['workflowId' => 'child-workflow-1'],
            ],
        ];
        $reflection = new ReflectionClass(AgenticWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('messages')->setValue($workflow, $messages);

        self::assertTrue((new ReflectionMethod(
            AgenticWorkflow::class,
            'hasConfirmedTerminalAction',
        ))->invoke($workflow, 'child-workflow-1'));
    }

    public function testNotificationOutageContinuesAsNewAndRecoversWithoutChildRerun(): void
    {
        $system    = self::message('system', 'system prompt');
        $batchTurn = [
            self::message('user', 'current Telegram batch'),
            [
                'role'    => 'assistant',
                'content' => [[
                    'type'      => 'toolCall',
                    'id'        => 'lookup-1',
                    'name'      => 'search_messages',
                    'arguments' => ['query' => 'context'],
                ]],
            ],
            [
                'role'       => 'toolResult',
                'toolName'   => 'search_messages',
                'toolCallId' => 'lookup-1',
                'content'    => [['type' => 'text', 'text' => 'context']],
            ],
            self::message('assistant', 'fallback answer'),
        ];
        $messages   = [$system, ...$batchTurn];
        $reflection = new ReflectionClass(AgenticWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('input')->setValue($workflow, new AgenticWorkflowInput(
            chatId: 1,
            chatType: 'private',
            model: 'test/model',
            tools: [],
        ));
        $reflection->getProperty('updatesQueue')->setValue($workflow, new MessageQueue());
        $reflection->getProperty('messages')->setValue($workflow, $messages);
        $reflection->getProperty('pendingBatchMessageCount')->setValue(
            $workflow,
            count($batchTurn),
        );
        $reflection->getProperty('pendingActorUserIds')->setValue($workflow, [1 => true]);
        $reflection->getProperty('pendingActorIdentityComplete')->setValue($workflow, true);
        $reflection->getProperty('pipelinePendingSince')->setValue($workflow, 123);
        $reflection->getProperty('agentRun')->setValue($workflow, 8);
        $reflection->getProperty('processedSinceContinueAsNew')->setValue($workflow, 100);

        $text  = 'fallback answer';
        $scope = 'terminal-scope-8';
        (new ReflectionMethod(
            AgenticWorkflow::class,
            'stageTerminalNotification',
        ))->invoke($workflow, $text, $scope);
        $firstDelay = (new ReflectionMethod(
            AgenticWorkflow::class,
            'markPendingTerminalNotificationFailed',
        ))->invoke($workflow);

        self::assertSame(1, $firstDelay);
        self::assertTrue((new ReflectionMethod(
            AgenticWorkflow::class,
            'shouldContinueAsNewAfterFailedAttempt',
        ))->invoke($workflow));
        self::assertSame(8, $reflection->getProperty('agentRun')->getValue($workflow));

        $continuation = (new ReflectionMethod(
            AgenticWorkflow::class,
            'continuationInput',
        ))->invoke($workflow, $messages);
        $converter = new AgenticWorkflowInputDataConverter();
        $payload   = $converter->toPayload($continuation);
        self::assertNotNull($payload);
        $continued = $converter->fromPayload(
            $payload,
            Type::create(AgenticWorkflowInput::class),
        );
        self::assertSame($text, $continued->pendingTerminalText);
        self::assertSame($scope, $continued->pendingTerminalScopeId);
        self::assertSame(1, $continued->notificationFailureCount);
        self::assertSame(count($batchTurn), $continued->pendingBatchMessageCount);
        self::assertSame($messages, $continued->messages);

        $keyMethod = new ReflectionMethod(
            AgenticWorkflow::class,
            'parentNotificationIdempotencyKey',
        );
        $firstKey = $keyMethod->invoke(null, 'parent-workflow', 'execution-chain', 8, $text);
        $retryKey = $keyMethod->invoke(
            null,
            'parent-workflow',
            'execution-chain',
            $continued->agentRun,
            $continued->pendingTerminalText,
        );
        self::assertSame($firstKey, $retryKey);

        try {
            (new ReflectionMethod(AgenticWorkflow::class, 'finishPipeline'))->invoke($workflow);
            self::fail('The batch must remain pending until terminal delivery is confirmed.');
        } catch (LogicException) {
            // Expected: a failed notification cannot consume the batch.
        }

        (new ReflectionMethod(
            AgenticWorkflow::class,
            'confirmPendingTerminalNotification',
        ))->invoke($workflow);
        (new ReflectionMethod(AgenticWorkflow::class, 'finishPipeline'))->invoke($workflow);

        self::assertSame(8, $reflection->getProperty('agentRun')->getValue($workflow));
        self::assertSame(0, $reflection->getProperty('pendingBatchMessageCount')->getValue($workflow));
        self::assertNull($reflection->getProperty('pendingTerminalText')->getValue($workflow));
        self::assertSame(0, $reflection->getProperty('notificationFailureCount')->getValue($workflow));
    }

    /**
     * @param string $role
     * @param string $text
     *
     * @return array{role: string, content: list<array{type: string, text: string}>}
     */
    private static function message(string $role, string $text): array
    {
        return [
            'role'    => $role,
            'content' => [['type' => 'text', 'text' => $text]],
        ];
    }
}
