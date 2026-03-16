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
            category: 'personal',
            content: 'Works as a software engineer at Google',
        );

        self::assertSame('@john_doe', $tool->userIdentifier);
        self::assertSame('personal', $tool->category);
        self::assertSame('Works as a software engineer at Google', $tool->content);
    }

    public function testSaveMemoryToolName(): void
    {
        self::assertSame('save_memory', SaveMemory::getName());
    }

    public function testSaveMemoryDescription(): void
    {
        $desc = SaveMemory::getDescription();
        self::assertStringContainsString('Save a fact', $desc);
        self::assertStringContainsString('user', $desc);
    }

    public function testRecallMemoryConstruction(): void
    {
        $tool = new RecallMemory(
            userIdentifier: '@john_doe',
            query: 'job',
        );

        self::assertSame('@john_doe', $tool->userIdentifier);
        self::assertSame('job', $tool->query);
    }

    public function testRecallMemoryDefaultValues(): void
    {
        $tool = new RecallMemory();

        self::assertNull($tool->userIdentifier);
        self::assertNull($tool->query);
    }

    public function testRecallMemoryToolName(): void
    {
        self::assertSame('recall_memory', RecallMemory::getName());
    }
}
