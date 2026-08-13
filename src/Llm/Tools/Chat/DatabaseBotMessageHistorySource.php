<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Chat;

use Cycle\Database\DatabaseInterface;

final readonly class DatabaseBotMessageHistorySource implements BotMessageHistorySourceInterface
{
    private const string TOOL_MESSAGE_CLASS      = 'Shanginn\Openai\ChatCompletion\Message\ToolMessage';
    private const string TELEGRAM_SUCCESS_PREFIX = 'Telegram API call succeeded: ';

    public function __construct(
        private DatabaseInterface $database,
    ) {}

    public function search(
        int $chatId,
        ?int $topicId,
        bool $topicScoped,
        array $queryTokens,
        ?int $startInclusive,
        ?int $endExclusive,
        int $limit,
    ): array {
        $limit = max(1, $limit);
        $items = [
            ...$this->legacyTelegramToolMessages(
                $chatId,
                $topicId,
                $topicScoped,
                $queryTokens,
                $startInclusive,
                $endExclusive,
                $limit,
            ),
            ...$this->telegramToolMessages(
                $chatId,
                $topicId,
                $topicScoped,
                $queryTokens,
                $startInclusive,
                $endExclusive,
                $limit,
            ),
        ];

        usort(
            $items,
            static fn (BotMessageHistoryItem $left, BotMessageHistoryItem $right): int => [
                $left->createdAt,
                $left->sourceOrder,
            ] <=> [
                $right->createdAt,
                $right->sourceOrder,
            ],
        );

        if (count($items) <= $limit) {
            return $items;
        }

        return $startInclusive === null
            ? array_slice($items, -$limit)
            : array_slice($items, 0, $limit);
    }

    /** @return array<string, mixed> */
    private static function decodedObject(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function positiveInteger(mixed $value): ?int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private static function likePattern(string $token): string
    {
        return '%' . strtr($token, [
            '!' => '!!',
            '%' => '!%',
            '_' => '!_',
        ]) . '%';
    }

    /** @param list<string> $tokens */
    private static function matchesTokens(string $text, array $tokens): bool
    {
        $text = mb_strtolower($text);
        foreach ($tokens as $token) {
            if (!str_contains($text, mb_strtolower($token))) {
                return false;
            }
        }

        return true;
    }

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
    private function legacyTelegramToolMessages(
        int $chatId,
        ?int $topicId,
        bool $topicScoped,
        array $queryTokens,
        ?int $startInclusive,
        ?int $endExclusive,
        int $limit,
    ): array {
        $where = [
            'chat_id = ?',
            'message_class = ?',
            "(payload::jsonb ->> 'content') LIKE 'Telegram API call succeeded:%'",
        ];
        $parameters = [$chatId, self::TOOL_MESSAGE_CLASS];
        $this->appendScope(
            $where,
            $parameters,
            $topicId,
            $topicScoped,
            $queryTokens,
            $startInclusive,
            $endExclusive,
            "(payload::jsonb ->> 'content')",
        );

        $direction = $startInclusive === null ? 'DESC' : 'ASC';
        $rows      = $this->database->query(sprintf(
            <<<'SQL'
                SELECT id, payload, created_at
                FROM llm_provider_responses
                WHERE %s
                ORDER BY created_at %s, id %s
                LIMIT ?
                SQL,
            implode(' AND ', $where),
            $direction,
            $direction,
        ), [...$parameters, $limit])->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $payload = self::decodedObject($row['payload'] ?? null);
            $sent    = $this->sentTelegramResponse($payload['content'] ?? null, $chatId);
            if ($sent === null) {
                continue;
            }

            $createdAt = self::positiveInteger($sent['date'] ?? null);
            if (
                $createdAt === null
                || ($startInclusive !== null && $createdAt < $startInclusive)
                || ($endExclusive !== null && $createdAt >= $endExclusive)
                || !self::matchesTokens($sent['text'], $queryTokens)
            ) {
                continue;
            }
            $items[] = new BotMessageHistoryItem($createdAt, $sent['text'], 1);
        }

        return $items;
    }

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
    private function telegramToolMessages(
        int $chatId,
        ?int $topicId,
        bool $topicScoped,
        array $queryTokens,
        ?int $startInclusive,
        ?int $endExclusive,
        int $limit,
    ): array {
        $where = [
            'completed_at IS NOT NULL',
            'result_json IS NOT NULL',
            "tool_name LIKE 'telegram_api_call:%'",
            "result_json::jsonb ->> 'name' = 'telegram_api_call'",
            "result_json::jsonb #>> '{metadata,chatId}' = ?",
            "result_json::jsonb #>> '{content,0,text}' LIKE 'Telegram API call succeeded:%'",
        ];
        $parameters = [(string) $chatId];
        $this->appendScope(
            $where,
            $parameters,
            $topicId,
            $topicScoped,
            $queryTokens,
            $startInclusive,
            $endExclusive,
            "(result_json::jsonb #>> '{content,0,text}')",
            timestampColumn: 'completed_at',
            topicExpression: "result_json::jsonb #>> '{metadata,topicId}'",
        );

        $direction = $startInclusive === null ? 'DESC' : 'ASC';
        $rows      = $this->database->query(sprintf(
            <<<'SQL'
                SELECT id, result_json, completed_at
                FROM tool_execution_records
                WHERE %s
                ORDER BY completed_at %s, id %s
                LIMIT ?
                SQL,
            implode(' AND ', $where),
            $direction,
            $direction,
        ), [...$parameters, $limit])->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result = self::decodedObject($row['result_json'] ?? null);
            $sent   = $this->sentTelegramMessage($result, $chatId);
            if ($sent === null) {
                continue;
            }

            $createdAt = self::positiveInteger($sent['date'] ?? null);
            if (
                $createdAt === null
                || ($startInclusive !== null && $createdAt < $startInclusive)
                || ($endExclusive !== null && $createdAt >= $endExclusive)
                || !self::matchesTokens($sent['text'], $queryTokens)
            ) {
                continue;
            }
            $items[] = new BotMessageHistoryItem($createdAt, $sent['text'], 2);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $result
     * @param int                  $trustedChatId
     *
     * @return array{text: string, date: mixed}|null
     */
    private function sentTelegramMessage(array $result, int $trustedChatId): ?array
    {
        if (($result['isError'] ?? false) === true) {
            return null;
        }

        foreach (($result['content'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $sent = $this->sentTelegramResponse($block['text'] ?? null, $trustedChatId);
            if ($sent !== null) {
                return $sent;
            }
        }

        return null;
    }

    /** @return array{text: string, date: mixed}|null */
    private function sentTelegramResponse(mixed $text, int $trustedChatId): ?array
    {
        if (!is_string($text) || !str_starts_with($text, self::TELEGRAM_SUCCESS_PREFIX)) {
            return null;
        }

        $response = self::decodedObject(substr($text, strlen(self::TELEGRAM_SUCCESS_PREFIX)));
        $message  = $response['result'] ?? null;
        if (
            ($response['ok'] ?? null) !== true
            || ($response['method'] ?? null) !== 'sendMessage'
            || !is_array($message)
        ) {
            return null;
        }
        $messageText = self::nonEmptyString($message['text'] ?? null);
        if ($messageText === null) {
            return null;
        }

        $returnedChatId = $message['chat']['id'] ?? null;
        if ($returnedChatId === null || (string) $returnedChatId !== (string) $trustedChatId) {
            return null;
        }

        return ['text' => $messageText, 'date' => $message['date'] ?? null];
    }

    /**
     * @param list<string> $where
     * @param list<mixed>  $parameters
     * @param list<string> $queryTokens
     * @param ?int         $topicId
     * @param bool         $topicScoped
     * @param ?int         $startInclusive
     * @param ?int         $endExclusive
     * @param string       $textColumn
     * @param string       $timestampColumn
     * @param string       $topicExpression
     */
    private function appendScope(
        array &$where,
        array &$parameters,
        ?int $topicId,
        bool $topicScoped,
        array $queryTokens,
        ?int $startInclusive,
        ?int $endExclusive,
        string $textColumn,
        string $timestampColumn = 'created_at',
        string $topicExpression = 'topic_id::text',
    ): void {
        if ($topicScoped) {
            if ($topicId === null) {
                $where[] = "{$topicExpression} IS NULL";
            } else {
                $where[]      = "{$topicExpression} = ?";
                $parameters[] = (string) $topicId;
            }
        }
        if ($startInclusive !== null) {
            $where[]      = "{$timestampColumn} >= ?";
            $parameters[] = max(1, $startInclusive - 86_400);
        }
        if ($endExclusive !== null) {
            $where[]      = "{$timestampColumn} < ?";
            $parameters[] = $endExclusive + 86_400;
        }
        foreach ($queryTokens as $token) {
            $where[]      = "{$textColumn} ILIKE ? ESCAPE '!'";
            $parameters[] = self::likePattern($token);
        }
    }
}
