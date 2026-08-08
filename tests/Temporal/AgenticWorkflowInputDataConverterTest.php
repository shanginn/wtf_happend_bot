<?php

declare(strict_types=1);

namespace Tests\Temporal;

use Bot\AgenticWorkflow\AgenticWorkflowInput;
use Bot\AgenticWorkflow\QueuedTelegramUpdate;
use Bot\Telegram\Factory;
use Bot\Telegram\Update;
use Bot\Temporal\AgenticWorkflowInputDataConverter;
use Bot\Temporal\TelegramDataConverter;
use Phenogram\Bindings\Types\Chat;
use Phenogram\Bindings\Types\Message;
use Phenogram\Bindings\Types\User;
use PHPUnit\Framework\TestCase;
use Temporal\DataConverter\BinaryConverter;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\JsonConverter;
use Temporal\DataConverter\NullConverter;
use Temporal\DataConverter\ProtoConverter;
use Temporal\DataConverter\ProtoJsonConverter;

final class AgenticWorkflowInputDataConverterTest extends TestCase
{
    public function testPendingTelegramUpdatesRoundTripThroughConfiguredConverter(): void
    {
        $update = new Update(
            updateId: 42,
            message: new Message(
                messageId: 101,
                date: 1_710_000_000,
                chat: new Chat(id: -100123, type: 'supergroup', title: 'Tea Room'),
                from: new User(id: 7, isBot: false, firstName: 'Alice', username: 'alice'),
                text: 'hello',
            ),
        );
        $input = new AgenticWorkflowInput(
            chatId: -100123,
            chatType: 'supergroup',
            topicId: 42,
            model: 'deepseek/deepseek-v4-flash',
            tools: [['name' => 'stay_silent']],
            messages: [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'previous']]]],
            processedCount: 3,
            agentRun: 2,
            pipelinePendingSince: 1_710_000_001,
            pendingUpdates: [new QueuedTelegramUpdate(
                update: $update,
                appendToAgent: false,
                ingestionId: 'ingestion-42',
            )],
            paused: true,
            callbackPending: true,
            droppedUpdateCount: 2,
            lastNotificationFailure: 'telegram unavailable',
            ingestionFailureCount: 4,
            contextFailureCount: 3,
            ingestionRetryPending: true,
            pendingBatchMessageCount: 1,
            pendingActorUserIds: [7, 9],
            pendingActorIdentityComplete: false,
            pendingTerminalText: 'retry this exact notification',
            pendingTerminalScopeId: 'terminal-scope-2',
            notificationFailureCount: 3,
        );

        $converter = new DataConverter(
            new AgenticWorkflowInputDataConverter(),
            new TelegramDataConverter(factory: new Factory()),
            new NullConverter(),
            new BinaryConverter(),
            new ProtoJsonConverter(),
            new ProtoConverter(),
            new JsonConverter(),
        );

        $decoded = $converter->fromPayload(
            $converter->toPayload($input),
            AgenticWorkflowInput::class,
        );

        self::assertInstanceOf(AgenticWorkflowInput::class, $decoded);
        self::assertSame($input->chatId, $decoded->chatId);
        self::assertSame($input->chatType, $decoded->chatType);
        self::assertSame($input->topicId, $decoded->topicId);
        self::assertSame($input->messages, $decoded->messages);
        self::assertSame($input->processedCount, $decoded->processedCount);
        self::assertTrue($decoded->paused);
        self::assertTrue($decoded->callbackPending);
        self::assertSame(2, $decoded->droppedUpdateCount);
        self::assertSame('telegram unavailable', $decoded->lastNotificationFailure);
        self::assertSame(4, $decoded->ingestionFailureCount);
        self::assertSame(3, $decoded->contextFailureCount);
        self::assertTrue($decoded->ingestionRetryPending);
        self::assertSame(1, $decoded->pendingBatchMessageCount);
        self::assertSame([7, 9], $decoded->pendingActorUserIds);
        self::assertFalse($decoded->pendingActorIdentityComplete);
        self::assertSame('retry this exact notification', $decoded->pendingTerminalText);
        self::assertSame('terminal-scope-2', $decoded->pendingTerminalScopeId);
        self::assertSame(3, $decoded->notificationFailureCount);
        self::assertCount(1, $decoded->pendingUpdates);
        self::assertInstanceOf(QueuedTelegramUpdate::class, $decoded->pendingUpdates[0]);
        self::assertFalse($decoded->pendingUpdates[0]->appendToAgent);
        self::assertSame('ingestion-42', $decoded->pendingUpdates[0]->ingestionId);
        self::assertInstanceOf(Update::class, $decoded->pendingUpdates[0]->update);
        self::assertSame(42, $decoded->pendingUpdates[0]->update->updateId);
        self::assertSame(-100123, $decoded->pendingUpdates[0]->update->effectiveChat?->id);
    }
}
