<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Chat;

interface BotMessageHistorySourceInterface
{
    /**
     * @param list<string> $queryTokens
     * @param int          $chatId
     * @param ?int         $topicId
     * @param bool         $topicScoped
     * @param ?int         $startInclusive
     * @param ?int         $endExclusive
     * @param int          $limit
     *
     * @return list<BotMessageHistoryItem>
     */
    public function search(
        int $chatId,
        ?int $topicId,
        bool $topicScoped,
        array $queryTokens,
        ?int $startInclusive,
        ?int $endExclusive,
        int $limit,
    ): array;
}
