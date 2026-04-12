<?php

declare(strict_types=1);

namespace Tests\Tools;

use Bot\Llm\Tools\Memory\RecallMemory;
use Bot\Llm\Tools\Memory\SaveMemory;
use Tests\TestCase;

class MemoryToolsTest extends TestCase
{
    public function testSaveMemoryConstruction(): void
    {
        $tool = new SaveMemory(
            userIdentifier: '@john_doe',
            memory: 'John works as a software engineer at Google',
            quote: 'I work at Google',
            context: 'They were introducing themselves to the group.',
        );

        self::assertSame('@john_doe', $tool->userIdentifier);
        self::assertSame('John works as a software engineer at Google', $tool->memory);
        self::assertSame('I work at Google', $tool->quote);
        self::assertSame('They were introducing themselves to the group.', $tool->context);
    }

    public function testSaveMemoryToolName(): void
    {
        self::assertSame('save_memory', SaveMemory::getName());
    }

    public function testSaveMemoryDescription(): void
    {
        $desc = SaveMemory::getDescription();
        self::assertStringContainsString('computed memory', $desc);
        self::assertStringContainsString('quote', $desc);
    }

    public function testRecallMemoryConstruction(): void
    {
        $tool = new RecallMemory(
            userIdentifier: '@john_doe',
            query: 'job',
            limit: 3,
        );

        self::assertSame('@john_doe', $tool->userIdentifier);
        self::assertSame('job', $tool->query);
        self::assertSame(3, $tool->limit);
    }

    public function testRecallMemoryDefaultValues(): void
    {
        $tool = new RecallMemory();

        self::assertNull($tool->userIdentifier);
        self::assertNull($tool->query);
        self::assertSame(10, $tool->limit);
    }

    public function testRecallMemoryToolName(): void
    {
        self::assertSame('recall_memory', RecallMemory::getName());
    }
}
