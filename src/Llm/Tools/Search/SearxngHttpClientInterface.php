<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Search;

interface SearxngHttpClientInterface
{
    /**
     * @param array<string, scalar|null> $query
     * @param string                     $url
     * @param int                        $timeoutSeconds
     *
     * @return array<string, mixed>
     */
    public function getJson(string $url, array $query, int $timeoutSeconds): array;
}
