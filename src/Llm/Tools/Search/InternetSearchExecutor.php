<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Search;

use InvalidArgumentException;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Throwable;

#[ActivityInterface(prefix: 'InternetSearchExecutor.')]
class InternetSearchExecutor
{
    private const string DEFAULT_BASE_URL = 'http://searxng:8080';
    private readonly SearxngSearchClient $client;

    public function __construct(
        ?SearxngSearchClient $client = null,
        ?string $baseUrl = null,
        ?int $timeoutSeconds = null,
    ) {
        $baseUrl ??= getenv('SEARCH_BASE_URL') ?: self::DEFAULT_BASE_URL;
        $this->client = $client ?? new SearxngSearchClient(
            baseUrl: $baseUrl,
            timeoutSeconds: $timeoutSeconds ?? 10,
        );
    }

    #[ActivityMethod]
    public function execute(int $chatId, InternetSearch $schema): string
    {
        try {
            $search = $this->client->search($schema);
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        } catch (Throwable $exception) {
            return 'Internet search failed: ' . $exception->getMessage();
        }

        $query = trim($schema->query);
        $lines = [
            sprintf('Internet search results for "%s"', $query),
            'Use the URLs below as sources when answering from these results.',
        ];

        if ($search['answers'] !== []) {
            $lines[] = "Direct answers:\n- " . implode("\n- ", $search['answers']);
        }

        if ($search['corrections'] !== []) {
            $lines[] = "Corrections:\n- " . implode("\n- ", $search['corrections']);
        }

        if ($search['suggestions'] !== []) {
            $lines[] = "Related searches:\n- " . implode("\n- ", $search['suggestions']);
        }

        if ($search['results'] === []) {
            $lines[] = 'No web results found.';

            return implode("\n\n", $lines);
        }

        foreach ($search['results'] as $index => $result) {
            $parts = [
                sprintf('%d. %s', $index + 1, $result['title']),
                'URL: ' . $result['url'],
            ];

            if ($result['content'] !== '') {
                $parts[] = 'Snippet: ' . $result['content'];
            }

            if ($result['publishedDate'] !== '') {
                $parts[] = 'Published: ' . $result['publishedDate'];
            }

            if ($result['engine'] !== '') {
                $parts[] = 'Engines: ' . $result['engine'];
            }

            $lines[] = implode("\n", $parts);
        }

        return implode("\n\n", $lines);
    }
}
