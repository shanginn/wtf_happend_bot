<?php

declare(strict_types=1);

namespace Tests\Llm\Tools\Memory;

use Bot\Llm\Tools\Memory\ForgetMemory;
use Bot\Llm\Tools\Memory\ForgetMemoryExecutor;
use Bot\Llm\Tools\Memory\RecallMemory;
use Bot\Llm\Tools\Memory\RecallMemoryExecutor;
use Bot\Llm\Tools\Memory\SaveMemory;
use Bot\Llm\Tools\Memory\SaveMemoryExecutor;
use Bot\Llm\Tools\Memory\UpdateMemory;
use Bot\Llm\Tools\Memory\UpdateMemoryExecutor;
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
                self::assertNull($messageThreadId);

                return $message;
            });

        $executor = new SaveMemoryExecutor($store, $api);

        self::assertSame('Memory saved', $executor->execute(-100123, $memory));
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

    public function testUpdateMemoryExecutorPassesChatIdExplicitly(): void
    {
        $store = $this->createMock(ParticipantMemoryStore::class);
        $request = new UpdateMemory(
            memory: 'Alice uses Neovim',
            quote: 'I switched to Neovim',
            context: 'They corrected their editor preference.',
            memoryId: 7,
        );

        $store
            ->expects($this->once())
            ->method('update')
            ->with(-100123, $request)
            ->willReturn('Memory updated for @alice (#7): Alice uses Neovim');

        $executor = new UpdateMemoryExecutor($store);

        self::assertSame(
            'Memory updated for @alice (#7): Alice uses Neovim',
            $executor->execute(-100123, $request),
        );
    }

    public function testForgetMemoryExecutorPassesChatIdExplicitly(): void
    {
        $store = $this->createMock(ParticipantMemoryStore::class);
        $request = new ForgetMemory(memoryId: 7);

        $store
            ->expects($this->once())
            ->method('forget')
            ->with(-100123, $request)
            ->willReturn('Memory forgotten for @alice (#7): Alice uses Vim');

        $executor = new ForgetMemoryExecutor($store);

        self::assertSame(
            'Memory forgotten for @alice (#7): Alice uses Vim',
            $executor->execute(-100123, $request),
        );
    }
}
