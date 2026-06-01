<?php

declare(strict_types=1);

namespace Tests\Llm\Tools\Search;

use Bot\Llm\Tools\Search\InternetSearch;
use Bot\Llm\Tools\Search\InternetSearchExecutor;
use Bot\Llm\Tools\Search\SearxngHttpClientInterface;
use Bot\Llm\Tools\Search\SearxngSearchClient;
use Tests\TestCase;

class InternetSearchExecutorTest extends TestCase
{
    public function testExecutorFormatsSearchResults(): void
    {
        $http = new FakeSearxngHttpClient([
            'answers' => ['PHP 8.4 is the current PHP feature release in this fixture.'],
            'suggestions' => ['PHP 8.4 release notes'],
            'results' => [
                [
                    'title' => 'PHP 8.4 Release Announcement',
                    'url' => 'https://www.php.net/releases/8.4/en.php',
                    'content' => '<b>PHP</b> 8.4 includes property hooks.',
                    'engines' => ['duckduckgo', 'brave'],
                    'publishedDate' => '2024-11-21',
                ],
            ],
        ]);
        $executor = new InternetSearchExecutor(
            client: new SearxngSearchClient('http://searxng.test', 4, $http),
        );

        $result = $executor->execute(-100123, new InternetSearch(
            query: 'PHP 8.4 release',
            limit: 3,
            timeRange: 'year',
            language: 'en',
            categories: 'general,news',
            safeSearch: 2,
        ));

        self::assertSame('http://searxng.test/search', $http->url);
        self::assertSame([
            'q' => 'PHP 8.4 release',
            'format' => 'json',
            'safesearch' => 2,
            'categories' => 'general,news',
            'language' => 'en',
            'time_range' => 'year',
        ], $http->query);
        self::assertSame(4, $http->timeoutSeconds);
        self::assertStringContainsString('Internet search results for "PHP 8.4 release"', $result);
        self::assertStringContainsString('Direct answers:', $result);
        self::assertStringContainsString('PHP 8.4 Release Announcement', $result);
        self::assertStringContainsString('URL: https://www.php.net/releases/8.4/en.php', $result);
        self::assertStringContainsString('Snippet: PHP 8.4 includes property hooks.', $result);
        self::assertStringContainsString('Engines: duckduckgo, brave', $result);
    }

    public function testExecutorReturnsNoResultsMessage(): void
    {
        $executor = new InternetSearchExecutor(
            client: new SearxngSearchClient('http://searxng.test', 10, new FakeSearxngHttpClient([
                'results' => [],
            ])),
        );

        $result = $executor->execute(-100123, new InternetSearch(query: 'unlikely query'));

        self::assertStringContainsString('No web results found.', $result);
    }

    public function testExecutorRejectsEmptyQuery(): void
    {
        $executor = new InternetSearchExecutor(
            client: new SearxngSearchClient('http://searxng.test', 10, new FakeSearxngHttpClient([])),
        );

        self::assertSame('Search query cannot be empty.', $executor->execute(-100123, new InternetSearch(query: '  ')));
    }

    public function testExecutorReportsSearchFailures(): void
    {
        $executor = new InternetSearchExecutor(
            client: new SearxngSearchClient('http://searxng.test', 10, new FailingSearxngHttpClient()),
        );

        $result = $executor->execute(-100123, new InternetSearch(query: 'news'));

        self::assertSame('Internet search failed: SearXNG returned HTTP 403.', $result);
    }

    public function testExecutorDefaultsNullTimeout(): void
    {
        $executor = new InternetSearchExecutor(
            client: null,
            baseUrl: 'http://searxng.test',
            timeoutSeconds: null,
        );

        $reflection = new \ReflectionClass($executor);
        $client = $reflection->getProperty('client')->getValue($executor);
        $clientReflection = new \ReflectionClass($client);

        self::assertSame(10, $clientReflection->getProperty('timeoutSeconds')->getValue($client));
    }
}

final class FakeSearxngHttpClient implements SearxngHttpClientInterface
{
    public string $url = '';

    /** @var array<string, scalar|null> */
    public array $query = [];

    public int $timeoutSeconds = 0;

    /**
     * @param array<string, mixed> $response
     */
    public function __construct(private readonly array $response) {}

    public function getJson(string $url, array $query, int $timeoutSeconds): array
    {
        $this->url = $url;
        $this->query = $query;
        $this->timeoutSeconds = $timeoutSeconds;

        return $this->response;
    }
}

final class FailingSearxngHttpClient implements SearxngHttpClientInterface
{
    public function getJson(string $url, array $query, int $timeoutSeconds): array
    {
        throw new \RuntimeException('SearXNG returned HTTP 403.');
    }
}
