<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Search;

use InvalidArgumentException;

final class SearxngSearchClient
{
    private readonly SearxngHttpClientInterface $httpClient;

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 10,
        ?SearxngHttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new StreamSearxngHttpClient();
    }

    /**
     * @param InternetSearch $search
     *
     * @return array{
     *     answers: list<string>,
     *     corrections: list<string>,
     *     suggestions: list<string>,
     *     results: list<array{title: string, url: string, content: string, engine: string, publishedDate: string}>
     * }
     */
    public function search(InternetSearch $search): array
    {
        $query = trim($search->query);
        if ($query === '') {
            throw new InvalidArgumentException('Search query cannot be empty.');
        }

        $payload = $this->httpClient->getJson(
            url: $this->endpoint(),
            query: $this->requestQuery($search, $query),
            timeoutSeconds: max(1, min($this->timeoutSeconds, 30)),
        );

        return [
            'answers'     => self::stringList($payload['answers'] ?? []),
            'corrections' => self::stringList($payload['corrections'] ?? []),
            'suggestions' => self::stringList($payload['suggestions'] ?? []),
            'results'     => $this->results($payload['results'] ?? [], max(1, min($search->limit, 10))),
        ];
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $text = self::stringValue($item);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return $items;
    }

    private static function stringValue(mixed $value, int $maxLength = 500): string
    {
        if (is_array($value)) {
            $value = array_is_list($value)
                ? implode(', ', array_map(self::stringValue(...), $value))
                : json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }

        if (!is_scalar($value)) {
            return '';
        }

        $string = html_entity_decode(strip_tags((string) $value), \ENT_QUOTES | \ENT_HTML5);
        $string = preg_replace('/\s+/u', ' ', $string) ?? $string;
        $string = trim($string);

        return mb_strlen($string) > $maxLength
            ? mb_substr($string, 0, $maxLength - 1) . '...'
            : $string;
    }

    /**
     * @param InternetSearch $search
     * @param string         $query
     *
     * @return array<string, scalar|null>
     */
    private function requestQuery(InternetSearch $search, string $query): array
    {
        $request = [
            'q'          => $query,
            'format'     => 'json',
            'safesearch' => max(0, min($search->safeSearch, 2)),
        ];

        $categories = trim($search->categories);
        if ($categories !== '') {
            $request['categories'] = $categories;
        }

        $language = trim($search->language);
        if ($language !== '' && strtolower($language) !== 'auto') {
            $request['language'] = $language;
        }

        $timeRange = $this->timeRange($search->timeRange);
        if ($timeRange !== null) {
            $request['time_range'] = $timeRange;
        }

        return $request;
    }

    private function endpoint(): string
    {
        $baseUrl = rtrim($this->baseUrl, '/');

        return str_ends_with($baseUrl, '/search') ? $baseUrl : $baseUrl . '/search';
    }

    private function timeRange(?string $timeRange): ?string
    {
        if ($timeRange === null) {
            return null;
        }

        $normalized = strtolower(trim($timeRange));

        return in_array($normalized, ['day', 'month', 'year'], true) ? $normalized : null;
    }

    /**
     * @param mixed $value
     * @param int   $limit
     *
     * @return list<array{title: string, url: string, content: string, engine: string, publishedDate: string}>
     */
    private function results(mixed $value, int $limit): array
    {
        if (!is_array($value)) {
            return [];
        }

        $results = [];
        foreach ($value as $result) {
            if (!is_array($result)) {
                continue;
            }

            $url = self::stringValue($result['url'] ?? null, 1000);
            if ($url === '') {
                continue;
            }

            $results[] = [
                'title'         => self::stringValue($result['title'] ?? $url, 180),
                'url'           => $url,
                'content'       => self::stringValue($result['content'] ?? $result['snippet'] ?? null, 700),
                'engine'        => self::stringValue($result['engines'] ?? $result['engine'] ?? null, 160),
                'publishedDate' => self::stringValue($result['publishedDate'] ?? $result['published_date'] ?? null, 80),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }
}
