<?php

declare(strict_types=1);

namespace Tests\Tools;

use Bot\Llm\Tools\Memory\RecallMemory;
use Bot\Llm\Tools\Memory\SaveMemory;
use Bot\Llm\Tools\Memory\ForgetMemory;
use Bot\Llm\Tools\Memory\UpdateMemory;
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

    public function testUpdateMemoryConstruction(): void
    {
        $tool = new UpdateMemory(
            memory: 'John now works at GitHub',
            quote: 'I moved to GitHub',
            context: 'They corrected their current employer.',
            memoryId: 42,
            userIdentifier: '@john_doe',
            currentMemory: 'John works at Google',
            query: 'employer',
        );

        self::assertSame('John now works at GitHub', $tool->memory);
        self::assertSame('I moved to GitHub', $tool->quote);
        self::assertSame('They corrected their current employer.', $tool->context);
        self::assertSame(42, $tool->memoryId);
        self::assertSame('@john_doe', $tool->userIdentifier);
        self::assertSame('John works at Google', $tool->currentMemory);
        self::assertSame('employer', $tool->query);
    }

    public function testUpdateMemoryToolName(): void
    {
        self::assertSame('update_memory', UpdateMemory::getName());
    }

    public function testForgetMemoryConstruction(): void
    {
        $tool = new ForgetMemory(
            memoryId: 42,
            userIdentifier: '@john_doe',
            query: 'employer',
            forgetAllForParticipant: true,
        );

        self::assertSame(42, $tool->memoryId);
        self::assertSame('@john_doe', $tool->userIdentifier);
        self::assertSame('employer', $tool->query);
        self::assertTrue($tool->forgetAllForParticipant);
    }

    public function testForgetMemoryDefaultValues(): void
    {
        $tool = new ForgetMemory();

        self::assertNull($tool->memoryId);
        self::assertNull($tool->userIdentifier);
        self::assertNull($tool->query);
        self::assertFalse($tool->forgetAllForParticipant);
    }

    public function testForgetMemoryToolName(): void
    {
        self::assertSame('forget_memory', ForgetMemory::getName());
    }
}
