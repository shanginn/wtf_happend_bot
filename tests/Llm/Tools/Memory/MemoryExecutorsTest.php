<?php

declare(strict_types=1);

namespace Tests\Llm\Tools\Memory;

use Bot\Llm\Tools\Memory\RecallMemory;
use Bot\Llm\Tools\Memory\RecallMemoryExecutor;
use Bot\Llm\Tools\Memory\SaveMemory;
use Bot\Llm\Tools\Memory\SaveMemoryExecutor;
use Bot\Memory\ParticipantMemoryStore;
use Tests\TestCase;

class MemoryExecutorsTest extends TestCase
{
    public function testSaveMemoryExecutorPassesChatIdExplicitly(): void
    {
        $store = $this->createMock(ParticipantMemoryStore::class);
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

        $executor = new SaveMemoryExecutor($store);

        self::assertSame('Memory saved', $executor->execute(-100123, $memory));
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
