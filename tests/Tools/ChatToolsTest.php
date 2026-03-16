<?php

declare(strict_types=1);

namespace Tests\Tools;

use Bot\Llm\Tools\Chat\CreatePoll;
use Bot\Llm\Tools\Chat\GetCurrentTime;
use Bot\Llm\Tools\Chat\SearchMessages;
use Tests\TestCase;

class ChatToolsTest extends TestCase
{
    // --- SearchMessages ---

    public function testSearchMessagesConstruction(): void
    {
        $tool = new SearchMessages(query: 'hello', username: '@bob', limit: 5);

        self::assertSame('hello', $tool->query);
        self::assertSame('@bob', $tool->username);
        self::assertSame(5, $tool->limit);
    }

    public function testSearchMessagesDefaults(): void
    {
        $tool = new SearchMessages(query: 'test');

        self::assertSame('test', $tool->query);
        self::assertNull($tool->username);
        self::assertSame(10, $tool->limit);
    }

    public function testSearchMessagesToolName(): void
    {
        self::assertSame('search_messages', SearchMessages::getName());
    }

    public function testSearchMessagesDescription(): void
    {
        self::assertStringContainsString('Search', SearchMessages::getDescription());
    }

    // --- GetCurrentTime ---

    public function testGetCurrentTimeConstruction(): void
    {
        $tool = new GetCurrentTime(timezone: 'Europe/Moscow');
        self::assertSame('Europe/Moscow', $tool->timezone);
    }

    public function testGetCurrentTimeDefault(): void
    {
        $tool = new GetCurrentTime();
        self::assertSame('UTC', $tool->timezone);
    }

    public function testGetCurrentTimeToolName(): void
    {
        self::assertSame('get_current_time', GetCurrentTime::getName());
    }

    // --- CreatePoll ---

    public function testCreatePollConstruction(): void
    {
        $tool = new CreatePoll(
            question: 'What for lunch?',
            options: ['Pizza', 'Sushi', 'Tacos'],
            isAnonymous: false,
            allowsMultipleAnswers: true,
        );

        self::assertSame('What for lunch?', $tool->question);
        self::assertSame(['Pizza', 'Sushi', 'Tacos'], $tool->options);
        self::assertFalse($tool->isAnonymous);
        self::assertTrue($tool->allowsMultipleAnswers);
    }

    public function testCreatePollDefaults(): void
    {
        $tool = new CreatePoll(question: 'Yes or no?', options: ['Yes', 'No']);

        self::assertTrue($tool->isAnonymous);
        self::assertFalse($tool->allowsMultipleAnswers);
    }

    public function testCreatePollToolName(): void
    {
        self::assertSame('create_poll', CreatePoll::getName());
    }
}
