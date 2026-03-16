<?php

declare(strict_types=1);

namespace Tests\Entity;

use Bot\Entity\UserMemory;
use Tests\TestCase;

class UserMemoryTest extends TestCase
{
    public function testConstruction(): void
    {
        $now = time();
        $memory = new UserMemory(
            chatId: -100123456789,
            userIdentifier: '@testuser',
            category: 'personal',
            content: 'Their real name is John Smith',
            createdAt: $now,
            updatedAt: $now,
        );

        self::assertSame(-100123456789, $memory->chatId);
        self::assertSame('@testuser', $memory->userIdentifier);
        self::assertSame('personal', $memory->category);
        self::assertSame('Their real name is John Smith', $memory->content);
        self::assertSame($now, $memory->createdAt);
        self::assertSame($now, $memory->updatedAt);
    }

    public function testCategoryValues(): void
    {
        $categories = ['personal', 'expertise', 'preference', 'note'];

        foreach ($categories as $category) {
            $memory = new UserMemory(
                chatId: 1,
                userIdentifier: '@user',
                category: $category,
                content: 'test',
                createdAt: 0,
                updatedAt: 0,
            );
            self::assertSame($category, $memory->category);
        }
    }
}
