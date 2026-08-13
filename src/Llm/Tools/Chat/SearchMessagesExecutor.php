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
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
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
    private const int PERIOD_CANDIDATE_LIMIT            = self::MAX_PAGE_OFFSET + self::MAX_RESULTS + 1;
    private const int MAX_PERIOD_DAYS                   = 366;
    private const int MAX_PAGE_OFFSET                   = 990;
    private const int MAX_QUERY_TOKENS                  = 12;
    private const int SNIPPET_RADIUS                    = 220;
    private const int MAX_SNIPPET_CHARS                 = 520;

    private readonly SerializerInterface $telegramSerializer;
    private readonly TelegramUpdateViewFactoryInterface $updateViewFactory;
    private readonly DateTimeZone $historyTimeZone;

    public function __construct(
        private readonly ORMInterface $orm,
        ?SerializerInterface $telegramSerializer = null,
        ?TelegramUpdateViewFactoryInterface $updateViewFactory = null,
        private readonly ?BotMessageHistorySourceInterface $botMessageHistory = null,
        string $historyTimeZone = 'Asia/Yekaterinburg',
    ) {
        $this->telegramSerializer = $telegramSerializer ?? new Serializer(new Factory());
        $this->updateViewFactory  = $updateViewFactory ?? new TelegramUpdateViewFactory();
        $this->historyTimeZone    = new DateTimeZone($historyTimeZone);
    }

    public function execute(
        int $chatId,
        string $queryText = '',
        ?string $usernameText = null,
        int $resultLimit = 10,
        ?string $onDate = null,
        ?string $fromDate = null,
        ?string $throughDate = null,
        array $relativeDay = [],
        int $offset = 0,
        ?int $referenceTimestamp = null,
    ): string {
        return $this->executeScoped(
            chatId: $chatId,
            topicId: null,
            spaceScoped: false,
            queryText: $queryText,
            usernameText: $usernameText,
            resultLimit: $resultLimit,
            onDate: $onDate,
            fromDate: $fromDate,
            throughDate: $throughDate,
            relativeDay: $relativeDay,
            offset: $offset,
            referenceTimestamp: $referenceTimestamp,
        );
    }

    public function executeInSpace(
        int $chatId,
        ?int $topicId,
        string $queryText = '',
        ?string $usernameText = null,
        int $resultLimit = 10,
        ?string $onDate = null,
        ?string $fromDate = null,
        ?string $throughDate = null,
        array $relativeDay = [],
        int $offset = 0,
        ?int $referenceTimestamp = null,
    ): string {
        return $this->executeScoped(
            chatId: $chatId,
            topicId: $topicId,
            spaceScoped: true,
            queryText: $queryText,
            usernameText: $usernameText,
            resultLimit: $resultLimit,
            onDate: $onDate,
            fromDate: $fromDate,
            throughDate: $throughDate,
            relativeDay: $relativeDay,
            offset: $offset,
            referenceTimestamp: $referenceTimestamp,
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

    private static function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function executeScoped(
        int $chatId,
        ?int $topicId,
        bool $spaceScoped,
        string $queryText,
        ?string $usernameText,
        int $resultLimit,
        ?string $onDate,
        ?string $fromDate,
        ?string $throughDate,
        array $relativeDay,
        int $offset,
        ?int $referenceTimestamp,
    ): string {
        /** @var UpdateRecordRepository $repo */
        $updateRepo = $this->orm->getRepository(UpdateRecord::class);

        $limit       = max(1, min($resultLimit, self::MAX_RESULTS));
        $query       = mb_strtolower(trim($queryText));
        $queryTokens = self::queryTokens($query);
        $username    = $usernameText === null ? null : mb_strtolower(trim($usernameText));
        $window      = max($limit, self::RECENT_WINDOW_MIN);
        $offset      = max(0, min($offset, self::MAX_PAGE_OFFSET));
        if (count($queryTokens) > self::MAX_QUERY_TOKENS) {
            return 'History search request invalid: query cannot contain more than 12 tokens.';
        }

        try {
            $period = $this->resolvePeriod(
                onDate: $onDate,
                fromDate: $fromDate,
                throughDate: $throughDate,
                relativeDay: $relativeDay,
                referenceTimestamp: $referenceTimestamp,
            );
        } catch (InvalidArgumentException $error) {
            return 'History search request invalid: ' . $error->getMessage();
        }

        if ($period !== null) {
            // Fetch from the start of the immutable historical period and page
            // after direct-text/participant filtering. Raw Telegram history can
            // contain service updates or nested reply text that must not create
            // gaps or false has_more results.
            $candidateLimit = min(
                self::PERIOD_CANDIDATE_LIMIT,
                $offset + max(($limit + 1) * self::SEARCH_CANDIDATE_MULTIPLIER, 100),
            );
            $records = $updateRepo->searchInPeriod(
                chatId: $chatId,
                startInclusive: $period['start'],
                endExclusive: $period['end'],
                tokens: $queryTokens,
                limit: $candidateLimit,
                offset: 0,
            );
            $items             = $this->loadUpdateItems($records, newestFirst: false);
            $botCandidateLimit = $candidateLimit;
        } elseif ($query === '') {
            $records = $spaceScoped
                ? $updateRepo->findLastNInTopic($chatId, $topicId, $window)
                : $updateRepo->findLastN($chatId, $window);
            $items             = $this->loadUpdateItems($records);
            $botCandidateLimit = $window;
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
            $items             = $this->loadUpdateItems($records);
            $botCandidateLimit = $candidateLimit;
        }

        if ($this->botMessageHistory !== null) {
            $items = [
                ...$items,
                ...$this->loadBotItems($this->botMessageHistory->search(
                    chatId: $chatId,
                    topicId: $topicId,
                    topicScoped: $spaceScoped,
                    queryTokens: $queryTokens,
                    startInclusive: $period['start'] ?? null,
                    endExclusive: $period['end'] ?? null,
                    limit: $botCandidateLimit,
                )),
            ];
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

        if ($period !== null) {
            $matches = array_slice($matches, $offset);
        }

        if ($matches === []) {
            if ($period !== null) {
                return sprintf(
                    'No Telegram messages or bot outputs found in %s%s.',
                    $period['label'],
                    $queryText === '' ? '' : ' matching "' . $queryText . '"',
                );
            }
            if ($query === '') {
                return 'No recent Telegram messages or bot outputs found.';
            }

            return 'No messages found matching "' . $queryText . '"'
                . ($usernameText === null ? '' : ' for ' . $usernameText)
                . '.';
        }

        $moreMatches   = $period !== null && count($matches) > $limit;
        $truncated     = $moreMatches && $offset >= self::MAX_PAGE_OFFSET;
        $hasMore       = $moreMatches && !$truncated;
        $selectedItems = $period === null
            ? array_slice($matches, -$limit)
            : array_slice($matches, 0, $limit);
        $selected = $query === '' && $period === null
            ? array_column($selectedItems, 'text')
            : array_map(
                fn (array $item): string => $this->formatSearchResult($item, $queryTokens),
                $selectedItems,
            );

        if ($period !== null) {
            $header = sprintf(
                'Telegram history for %s (offset %d; has_more=%s%s)',
                $period['label'],
                $offset,
                $hasMore ? 'true' : 'false',
                $hasMore
                    ? '; next_offset=' . ($offset + $limit)
                    : ($truncated ? '; truncated=true; pagination_limit_reached=true' : ''),
            );
        } else {
            $header = $query === ''
                ? 'Recent inbound Telegram history and bot outputs'
                : 'Relevant inbound Telegram history and bot outputs'
                    . ' (searched persisted messages and bot outputs; showing latest compact matches)';
        }

        return $header . "\n\n" . implode("\n\n---\n\n", $selected);
    }

    /**
     * @param array<string, mixed> $relativeDay
     * @param ?string              $onDate
     * @param ?string              $fromDate
     * @param ?string              $throughDate
     * @param ?int                 $referenceTimestamp
     *
     * @return array{start: int, end: int, label: string}|null
     */
    private function resolvePeriod(
        ?string $onDate,
        ?string $fromDate,
        ?string $throughDate,
        array $relativeDay,
        ?int $referenceTimestamp,
    ): ?array {
        $onDate      = self::nullableTrim($onDate);
        $fromDate    = self::nullableTrim($fromDate);
        $throughDate = self::nullableTrim($throughDate);
        $hasRange    = $fromDate !== null || $throughDate !== null;
        $hasRelative = $relativeDay !== [];
        $modeCount   = (int) ($onDate !== null) + (int) $hasRange + (int) $hasRelative;

        if ($modeCount === 0) {
            return null;
        }
        if ($modeCount !== 1) {
            throw new InvalidArgumentException(
                'use exactly one of on_date, from_date/through_date, or relative_day.',
            );
        }

        if ($onDate !== null) {
            $start = $this->parseDate($onDate);
            $end   = $start->modify('+1 day');
        } elseif ($hasRange) {
            if ($fromDate === null || $throughDate === null) {
                throw new InvalidArgumentException('from_date and through_date must be supplied together.');
            }
            $start = $this->parseDate($fromDate);
            $last  = $this->parseDate($throughDate);
            $end   = $last->modify('+1 day');
            if ($end <= $start) {
                throw new InvalidArgumentException('through_date must not precede from_date.');
            }
            if ((int) $start->diff($end)->format('%a') > self::MAX_PERIOD_DAYS) {
                throw new InvalidArgumentException('date ranges cannot exceed 366 calendar days.');
            }
        } else {
            if ($referenceTimestamp === null || $referenceTimestamp <= 0) {
                throw new InvalidArgumentException('relative_day requires a trusted current-message timestamp.');
            }
            $expectedKeys = ['years_ago', 'months_ago', 'days_ago'];
            foreach (array_keys($relativeDay) as $key) {
                if (!is_string($key) || !in_array($key, $expectedKeys, true)) {
                    throw new InvalidArgumentException('relative_day contains an unknown field.');
                }
            }
            $values = [];
            foreach ($expectedKeys as $key) {
                $value = $relativeDay[$key] ?? 0;
                if (!is_int($value) || $value < 0) {
                    throw new InvalidArgumentException("relative_day.{$key} must be a non-negative integer.");
                }
                $values[$key] = $value;
            }
            if (array_sum($values) === 0) {
                throw new InvalidArgumentException('relative_day must move at least one calendar unit into the past.');
            }
            if ($values['years_ago'] > 20 || $values['months_ago'] > 240 || $values['days_ago'] > 7300) {
                throw new InvalidArgumentException('relative_day exceeds the supported history range.');
            }

            $anchor = (new DateTimeImmutable('@' . $referenceTimestamp))
                ->setTimezone($this->historyTimeZone);
            $start = $this->subtractCalendar(
                $anchor,
                $values['years_ago'],
                $values['months_ago'],
                $values['days_ago'],
            )->setTime(0, 0);
            $end = $start->modify('+1 day');
        }

        return [
            'start' => $start->getTimestamp(),
            'end'   => $end->getTimestamp(),
            'label' => $start->format('Y-m-d') . ($end->diff($start)->days === 1
                ? ''
                : ' through ' . $end->modify('-1 day')->format('Y-m-d'))
                . ' in ' . $this->historyTimeZone->getName(),
        ];
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date   = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->historyTimeZone);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$date instanceof DateTimeImmutable
            || $date->format('Y-m-d') !== $value
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new InvalidArgumentException("invalid calendar date {$value}; expected YYYY-MM-DD.");
        }

        return $date;
    }

    private function subtractCalendar(
        DateTimeImmutable $anchor,
        int $years,
        int $months,
        int $days,
    ): DateTimeImmutable {
        $totalMonths = ((int) $anchor->format('Y') - $years) * 12
            + ((int) $anchor->format('n') - 1)
            - $months;
        $year       = intdiv($totalMonths, 12);
        $monthIndex = $totalMonths % 12;
        if ($monthIndex < 0) {
            --$year;
            $monthIndex += 12;
        }
        $month = $monthIndex + 1;
        $day   = min(
            (int) $anchor->format('j'),
            (int) (new DateTimeImmutable("{$year}-{$month}-01", $this->historyTimeZone))->format('t'),
        );
        $date = $anchor->setDate($year, $month, $day);

        return $days === 0 ? $date : $date->modify("-{$days} days");
    }

    /**
     * @param array<UpdateRecord> $records
     * @param bool                $newestFirst
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
    private function loadUpdateItems(array $records, bool $newestFirst = true): array
    {
        $items = [];

        foreach ($newestFirst ? array_reverse($records) : $records as $record) {
            $decoded    = json_decode($record->update, true, flags: \JSON_THROW_ON_ERROR);
            $update     = $this->telegramSerializer->deserialize($decoded, UpdateInterface::class);
            $view       = $this->updateViewFactory->create($update);
            $directText = trim((string) ($view->memoryEvidenceText ?? ''));
            if ($directText === '') {
                continue;
            }

            $items[] = [
                'createdAt'          => $record->createdAt,
                'sourceOrder'        => 0,
                'participant'        => $view->participantReference,
                'participantAliases' => self::participantAliases($update, $view->participantReference),
                // Search only text authored in this inbound update. The rich
                // Telegram view also contains quoted/replied messages, which
                // can include an earlier bot answer. Treating that nested text
                // as participant evidence created circular "memories" from the
                // bot's own prior claims.
                'searchable' => mb_strtolower($directText),
                'text'       => $directText,
            ];
        }

        return $items;
    }

    /**
     * @param list<BotMessageHistoryItem> $records
     *
     * @return list<array{
     *     createdAt: int,
     *     sourceOrder: int,
     *     participant: string,
     *     participantAliases: list<string>,
     *     searchable: string,
     *     text: string
     * }>
     */
    private function loadBotItems(array $records): array
    {
        return array_map(
            static fn (BotMessageHistoryItem $record): array => [
                'createdAt'          => $record->createdAt,
                'sourceOrder'        => $record->sourceOrder,
                'participant'        => 'bot',
                'participantAliases' => [
                    'bot',
                    'assistant',
                    'wtf_happend_bot',
                    'wtf_happened_bot',
                    'wtf happend??',
                ],
                'searchable' => mb_strtolower($record->text),
                'text'       => "Bot output\nFrom: bot\n\nText:\n{$record->text}",
            ],
            $records,
        );
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
            (new DateTimeImmutable('@' . $item['createdAt']))
                ->setTimezone($this->historyTimeZone)
                ->format('Y-m-d H:i:s T'),
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
