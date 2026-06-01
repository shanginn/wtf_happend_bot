<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Search;

use JsonException;
use RuntimeException;

final class StreamSearxngHttpClient implements SearxngHttpClientInterface
{
    public function getJson(string $url, array $query, int $timeoutSeconds): array
    {
        $requestUrl = $url . '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        $context    = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => "Accept: application/json\r\nUser-Agent: wtf-happend-bot/1.0\r\n",
                'timeout'         => $timeoutSeconds,
                'ignore_errors'   => true,
                'follow_location' => 1,
                'max_redirects'   => 3,
            ],
        ]);

        $body       = @file_get_contents($requestUrl, false, $context);
        $statusCode = self::statusCode($http_response_header ?? []);

        if ($body === false) {
            throw new RuntimeException(sprintf(
                'Unable to reach SearXNG at %s.',
                $url,
            ));
        }

        if ($statusCode !== null && $statusCode >= 400) {
            $hint = $statusCode === 403
                ? ' Make sure JSON output is enabled in SearXNG settings.yml under search.formats.'
                : '';

            throw new RuntimeException(sprintf(
                'SearXNG returned HTTP %d.%s',
                $statusCode,
                $hint,
            ));
        }

        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('SearXNG returned invalid JSON: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('SearXNG returned an unexpected JSON response.');
        }

        return $decoded;
    }

    /**
     * @param array<string> $headers
     */
    private static function statusCode(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }
}
