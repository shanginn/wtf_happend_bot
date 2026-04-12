<?php

declare(strict_types=1);

namespace Tests\Llm\Tools\Memory;

use Bot\Llm\Tools\Memory\RecallMemory;
use Bot\Llm\Tools\Memory\RecallMemoryExecutor;
use Bot\Llm\Tools\Memory\SaveMemory;
use Bot\Llm\Tools\Memory\SaveMemoryExecutor;
use Bot\Memory\ParticipantMemoryStore;
use Phenogram\Bindings\ApiInterface;
use Phenogram\Bindings\Types\Interfaces\MessageInterface;
use Tests\TestCase;

class MemoryExecutorsTest extends TestCase
{
    public function testSaveMemoryExecutorPassesChatIdExplicitlyAndNotifiesChat(): void
    {
        $store = $this->createMock(ParticipantMemoryStore::class);
        $api = $this->createMock(ApiInterface::class);
        $memory = new SaveMemory(
            userIdentifier: '@alice',
            memory: 'Alice real name is Alice',
            quote: 'My name is Alice',
            context: 'They introduced themselves.',
        );

        $store
            ->expects($this->once())
            ->method('save')
            ->with(-100123, $memory)
            ->willReturn('Memory saved');

        $message = $this->createStub(MessageInterface::class);

        $api
            ->expects($this->once())
            ->method('sendMessage')
            ->willReturnCallback(function (
                int|string $chatId,
                string $text,
                ?string $businessConnectionId = null,
                ?int $messageThreadId = null,
            ) use ($message): MessageInterface {
                self::assertSame(-100123, $chatId);
                self::assertSame('Память обновлена', $text);
                self::assertNull($businessConnectionId);
                self::assertSame(777, $messageThreadId);

                return $message;
            });

        $executor = new SaveMemoryExecutor($store, $api);

        self::assertSame('Memory saved', $executor->execute(-100123, $memory, 777));
    }

    public function testSaveMemoryExecutorDoesNotNotifyChatWhenSaveFails(): void
    {
        $store = $this->createMock(ParticipantMemoryStore::class);
        $api = $this->createMock(ApiInterface::class);
        $memory = new SaveMemory(
            userIdentifier: '',
            memory: 'Alice real name is Alice',
            quote: 'My name is Alice',
            context: 'They introduced themselves.',
        );

        $store
            ->expects($this->once())
            ->method('save')
            ->with(-100123, $memory)
            ->willReturn('Memory not saved: participant reference is required.');

        $api
            ->expects($this->never())
            ->method('sendMessage');

        $executor = new SaveMemoryExecutor($store, $api);

        self::assertSame(
            'Memory not saved: participant reference is required.',
            $executor->execute(-100123, $memory),
        );
    }

    public function testRecallMemoryExecutorPassesChatIdExplicitly(): void
    {
        $store = $this->createMock(ParticipantMemoryStore::class);
        $query = new RecallMemory(userIdentifier: '@alice', limit: 5);

        $store
            ->expects($this->once())
            ->method('recall')
            ->with(-100123, $query)
            ->willReturn('Memories for @alice');

        $executor = new RecallMemoryExecutor($store);

        self::assertSame('Memories for @alice', $executor->execute(-100123, $query));
    }
}
