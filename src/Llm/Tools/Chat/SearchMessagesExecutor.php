<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Chat;

use Bot\Entity\UpdateRecord;
use Bot\Entity\UpdateRecord\UpdateRecordRepository;
use Bot\Telegram\Factory;
use Bot\Telegram\TelegramUpdateViewFactory;
use Bot\Telegram\TelegramUpdateViewFactoryInterface;
use Bot\Telegram\Update;
use Cycle\ORM\ORMInterface;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\SerializerInterface;
use Phenogram\Bindings\Types\Interfaces\ChatInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Bindings\Types\Interfaces\UserInterface;

final class SearchMessagesExecutor
{
    private const int MAX_RESULTS                       = 30;
    private const int RECENT_WINDOW_MIN                 = 50;
    private const int SEARCH_CANDIDATE_MULTIPLIER       = 10;
    private const int SEARCH_CANDIDATE_LIMIT_PER_SOURCE = 300;
    private const int SNIPPET_RADIUS                    = 220;
    private const int MAX_SNIPPET_CHARS                 = 520;

    private readonly SerializerInterface $telegramSerializer;
    private readonly TelegramUpdateViewFactoryInterface $updateViewFactory;

    public function __construct(
        private readonly ORMInterface $orm,
        ?SerializerInterface $telegramSerializer = null,
        ?TelegramUpdateViewFactoryInterface $updateViewFactory = null,
    ) {
        $this->telegramSerializer = $telegramSerializer ?? new Serializer(new Factory());
        $this->updateViewFactory  = $updateViewFactory ?? new TelegramUpdateViewFactory();
    }

    public function execute(
        int $chatId,
        string $queryText = '',
        ?string $usernameText = null,
        int $resultLimit = 10,
    ): string {
        return $this->executeScoped(
            chatId: $chatId,
            topicId: null,
            spaceScoped: false,
            queryText: $queryText,
            usernameText: $usernameText,
            resultLimit: $resultLimit,
        );
    }

    public function executeInSpace(
        int $chatId,
        ?int $topicId,
        string $queryText = '',
        ?string $usernameText = null,
        int $resultLimit = 10,
    ): string {
        return $this->executeScoped(
            chatId: $chatId,
            topicId: $topicId,
            spaceScoped: true,
            queryText: $queryText,
            usernameText: $usernameText,
            resultLimit: $resultLimit,
        );
    }

    /**
     * @param UpdateInterface $update
     * @param ?string         $participantReference
     *
     * @return list<string>
     */
    private static function participantAliases(
        UpdateInterface $update,
        ?string $participantReference,
    ): array {
        $aliases = $participantReference === null ? [] : [$participantReference];
        if (!$update instanceof Update) {
            return $aliases;
        }

        $sender = $update->effectiveSender;
        if ($sender instanceof UserInterface) {
            $aliases[] = 'telegram_user:' . $sender->id;
            if ($sender->username !== null && $sender->username !== '') {
                $aliases[] = '@' . $sender->username;
            }
        } elseif ($sender instanceof ChatInterface) {
            $aliases[] = 'telegram_chat:' . $sender->id;
            if ($sender->username !== null && $sender->username !== '') {
                $aliases[] = '@' . $sender->username;
            }
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @param string $query
     *
     * @return list<string>
     */
    private static function queryTokens(string $query): array
    {
        $tokens = preg_split('/\s+/', mb_strtolower(trim($query))) ?: [];

        return array_values(array_filter(
            $tokens,
            static fn (string $token): bool => $token !== '',
        ));
    }

    private function executeScoped(
        int $chatId,
        ?int $topicId,
        bool $spaceScoped,
        string $queryText,
        ?string $usernameText,
        int $resultLimit,
    ): string {
        /** @var UpdateRecordRepository $repo */
        $updateRepo = $this->orm->getRepository(UpdateRecord::class);

        $limit       = max(1, min($resultLimit, self::MAX_RESULTS));
        $query       = mb_strtolower(trim($queryText));
        $queryTokens = self::queryTokens($query);
        $username    = $usernameText === null ? null : mb_strtolower(trim($usernameText));
        $window      = max($limit, self::RECENT_WINDOW_MIN);

        if ($query === '') {
            $records = $spaceScoped
                ? $updateRepo->findLastNInTopic($chatId, $topicId, $window)
                : $updateRepo->findLastN($chatId, $window);
            $items = $this->loadUpdateItems($records);
        } else {
            $candidateLimit = min(
                self::SEARCH_CANDIDATE_LIMIT_PER_SOURCE,
                max($limit * self::SEARCH_CANDIDATE_MULTIPLIER, $limit),
            );

            $records = $spaceScoped
                ? $updateRepo->searchByPayloadTextInTopic(
                    $chatId,
                    $topicId,
                    $queryTokens,
                    $candidateLimit,
                )
                : $updateRepo->searchByPayloadText($chatId, $queryTokens, $candidateLimit);
            $items = $this->loadUpdateItems($records);
        }

        usort(
            $items,
            static fn (array $left, array $right): int => [$left['createdAt'], $left['sourceOrder']]
                <=> [$right['createdAt'], $right['sourceOrder']],
        );

        $matches = array_values(array_filter(
            $items,
            fn (array $item): bool => $this->matchesUsername($item['participantAliases'], $username)
                && $this->matchesQuery($item['searchable'], $query),
        ));

        if ($matches === []) {
            if ($query === '') {
                return 'No recent inbound Telegram messages found.';
            }

            return 'No messages found matching "' . $queryText . '"'
                . ($usernameText === null ? '' : ' for ' . $usernameText)
                . '.';
        }

        $selectedItems = array_slice($matches, -$limit);
        $selected      = $query === ''
            ? array_column($selectedItems, 'text')
            : array_map(
                fn (array $item): string => $this->formatSearchResult($item, $queryTokens),
                $selectedItems,
            );

        $header = $query === ''
            ? 'Recent inbound Telegram history'
            : 'Relevant inbound Telegram history (searched persisted updates; showing latest compact matches)';

        return $header . "\n\n" . implode("\n\n---\n\n", $selected);
    }

    /**
     * @param array<UpdateRecord> $records
     *
     * @return list<array{
     *     createdAt: int,
     *     sourceOrder: int,
     *     participant: ?string,
     *     participantAliases: list<string>,
     *     searchable: string,
     *     text: string
     * }>
     */
    private function loadUpdateItems(array $records): array
    {
        $items = [];

        foreach (array_reverse($records) as $record) {
            $decoded = json_decode($record->update, true, flags: \JSON_THROW_ON_ERROR);
            $update  = $this->telegramSerializer->deserialize($decoded, UpdateInterface::class);
            $view    = $this->updateViewFactory->create($update);

            $items[] = [
                'createdAt'          => $record->createdAt,
                'sourceOrder'        => 0,
                'participant'        => $view->participantReference,
                'participantAliases' => self::participantAliases($update, $view->participantReference),
                'searchable'         => mb_strtolower($view->text),
                'text'               => $view->text,
            ];
        }

        return $items;
    }

    /**
     * @param list<string> $participantAliases
     * @param ?string      $username
     */
    private function matchesUsername(array $participantAliases, ?string $username): bool
    {
        if ($username === null || $username === '') {
            return true;
        }

        $needle = ltrim($username, '@');

        foreach ($participantAliases as $alias) {
            if (ltrim(mb_strtolower($alias), '@') === $needle) {
                return true;
            }
        }

        return false;
    }

    private function matchesQuery(string $searchable, string $query): bool
    {
        if ($query === '') {
            return true;
        }

        foreach (self::queryTokens($query) as $token) {
            if ($token !== '' && !str_contains($searchable, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{createdAt: int, participant: ?string, text: string} $item
     * @param list<string>                                              $queryTokens
     */
    private function formatSearchResult(array $item, array $queryTokens): string
    {
        $participant = $item['participant'];
        if ($participant === null || $participant === '') {
            $participant = 'unknown';
        }

        return sprintf(
            '[%s] %s: %s',
            date('Y-m-d H:i:s', $item['createdAt']),
            $participant,
            $this->snippet($item['text'], $queryTokens),
        );
    }

    /**
     * @param list<string> $queryTokens
     * @param string       $text
     */
    private function snippet(string $text, array $queryTokens): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($normalized === '') {
            return '';
        }

        $position = null;
        foreach ($queryTokens as $token) {
            $tokenPosition = mb_stripos($normalized, $token);
            if ($tokenPosition === false) {
                continue;
            }

            $position = $position === null ? $tokenPosition : min($position, $tokenPosition);
        }

        if ($position === null) {
            return $this->truncateAround($normalized, 0);
        }

        return $this->truncateAround($normalized, max(0, $position - self::SNIPPET_RADIUS));
    }

    private function truncateAround(string $text, int $start): string
    {
        $length  = mb_strlen($text);
        $start   = min($start, max(0, $length - self::MAX_SNIPPET_CHARS));
        $snippet = mb_substr($text, $start, self::MAX_SNIPPET_CHARS);

        if ($start > 0) {
            $snippet = '... ' . ltrim($snippet);
        }

        if ($start + self::MAX_SNIPPET_CHARS < $length) {
            $snippet = rtrim($snippet) . ' ...';
        }

        return $snippet;
    }
}
