<?php

declare(strict_types=1);

namespace Tests\Tools;

use Bot\Llm\Tools\Chat\CreatePoll;
use Bot\Llm\Tools\Chat\GetCurrentTime;
use Bot\Llm\Tools\Chat\SearchMessages;
use Bot\Llm\Tools\Search\InternetSearch;
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
        $tool = new SearchMessages();

        self::assertSame('', $tool->query);
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

    // --- InternetSearch ---

    public function testInternetSearchConstruction(): void
    {
        $tool = new InternetSearch(
            query: 'SearXNG JSON API',
            limit: 3,
            timeRange: 'month',
            language: 'en',
            categories: 'general,news',
            safeSearch: 2,
        );

        self::assertSame('SearXNG JSON API', $tool->query);
        self::assertSame(3, $tool->limit);
        self::assertSame('month', $tool->timeRange);
        self::assertSame('en', $tool->language);
        self::assertSame('general,news', $tool->categories);
        self::assertSame(2, $tool->safeSearch);
    }

    public function testInternetSearchDefaults(): void
    {
        $tool = new InternetSearch(query: 'latest php release');

        self::assertSame(5, $tool->limit);
        self::assertNull($tool->timeRange);
        self::assertSame('auto', $tool->language);
        self::assertSame('general', $tool->categories);
        self::assertSame(1, $tool->safeSearch);
    }

    public function testInternetSearchToolName(): void
    {
        self::assertSame('internet_search', InternetSearch::getName());
    }

    public function testInternetSearchDescription(): void
    {
        self::assertStringContainsString('public internet', InternetSearch::getDescription());
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
