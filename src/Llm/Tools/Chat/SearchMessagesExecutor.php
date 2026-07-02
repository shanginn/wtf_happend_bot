<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Chat;

use Bot\AgenticWorkflow\AgenticToolset;
use Bot\Entity\LlmProviderResponse;
use Bot\Entity\UpdateRecord;
use Bot\Llm\ProviderHistory\LlmProviderType;
use Bot\Openai\CompatibleOpenaiSerializer;
use Bot\Telegram\Factory;
use Bot\Telegram\TelegramUpdateViewFactory;
use Bot\Telegram\TelegramUpdateViewFactoryInterface;
use Cycle\ORM\ORMInterface;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\SerializerInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Shanginn\Openai\ChatCompletion\Message\AssistantMessage;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Shanginn\Openai\Openai\OpenaiSerializerInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'SearchMessagesExecutor.')]
class SearchMessagesExecutor
{
    private const int MAX_RESULTS = 30;
    private const int RECENT_WINDOW_MIN = 50;
    private const int SEARCH_CANDIDATE_MULTIPLIER = 10;
    private const int SEARCH_CANDIDATE_LIMIT_PER_SOURCE = 300;
    private const int SNIPPET_RADIUS = 220;
    private const int MAX_SNIPPET_CHARS = 520;

    private readonly SerializerInterface $telegramSerializer;
    private readonly TelegramUpdateViewFactoryInterface $updateViewFactory;
    private readonly OpenaiSerializerInterface $openaiSerializer;

    public function __construct(
        private readonly ORMInterface $orm,
        ?SerializerInterface $telegramSerializer = null,
        ?TelegramUpdateViewFactoryInterface $updateViewFactory = null,
        ?OpenaiSerializerInterface $openaiSerializer = null,
    ) {
        $this->telegramSerializer = $telegramSerializer ?? new Serializer(new Factory());
        $this->updateViewFactory = $updateViewFactory ?? new TelegramUpdateViewFactory();
        $this->openaiSerializer = $openaiSerializer ?? new CompatibleOpenaiSerializer();
    }

    #[ActivityMethod]
    public function execute(int $chatId, SearchMessages $schema): string
    {
        /** @var \Bot\Entity\UpdateRecord\UpdateRecordRepository $repo */
        $updateRepo = $this->orm->getRepository(UpdateRecord::class);
        /** @var \Bot\Entity\LlmProviderResponse\LlmProviderResponseRepository $responseRepo */
        $responseRepo = $this->orm->getRepository(LlmProviderResponse::class);

        $limit = max(1, min($schema->limit, self::MAX_RESULTS));
        $query = mb_strtolower(trim($schema->query));
        $queryTokens = self::queryTokens($query);
        $username = $schema->username === null ? null : ltrim(mb_strtolower(trim($schema->username)), '@');
        $window = max($limit, self::RECENT_WINDOW_MIN);

        if ($query === '') {
            $items = [
                ...$this->loadUpdateItems($updateRepo->findLastN($chatId, $window)),
                ...$this->loadAssistantItems($responseRepo->findLastNByChat($chatId, LlmProviderType::Openai, $window)),
            ];
        } else {
            $candidateLimit = min(
                self::SEARCH_CANDIDATE_LIMIT_PER_SOURCE,
                max($limit * self::SEARCH_CANDIDATE_MULTIPLIER, $limit),
            );

            $items = [
                ...$this->loadUpdateItems($updateRepo->searchByPayloadText($chatId, $queryTokens, $candidateLimit)),
                ...$this->loadAssistantItems($responseRepo->searchByPayloadText(
                    $chatId,
                    LlmProviderType::Openai,
                    $queryTokens,
                    $candidateLimit,
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
            fn (array $item): bool => $this->matchesUsername($item['participant'], $username)
                && $this->matchesQuery($item['searchable'], $query),
        ));

        if ($matches === []) {
            if ($query === '') {
                return 'No recent messages found in chat history.';
            }

            return 'No messages found matching "' . $schema->query . '"'
                . ($schema->username === null ? '' : ' for ' . $schema->username)
                . '.';
        }

        $selectedItems = array_slice($matches, -$limit);
        $selected = $query === ''
            ? array_column($selectedItems, 'text')
            : array_map(
                fn (array $item): string => $this->formatSearchResult($item, $queryTokens),
                $selectedItems,
            );

        $header = $query === ''
            ? 'Recent chat history'
            : 'Relevant chat history (searched full persisted chat history; showing latest compact matches)';

        return $header . "\n\n" . implode("\n\n---\n\n", $selected);
    }

    /**
     * @param array<UpdateRecord> $records
     * @return list<array{createdAt: int, sourceOrder: int, participant: ?string, searchable: string, text: string}>
     */
    private function loadUpdateItems(array $records): array
    {
        $items = [];

        foreach (array_reverse($records) as $record) {
            $decoded = json_decode($record->update, true, flags: \JSON_THROW_ON_ERROR);
            $update = $this->telegramSerializer->deserialize($decoded, UpdateInterface::class);
            $view = $this->updateViewFactory->create($update);

            $items[] = [
                'createdAt' => $record->createdAt,
                'sourceOrder' => 0,
                'participant' => $view->participantReference,
                'searchable' => mb_strtolower($view->text),
                'text' => $view->text,
            ];
        }

        return $items;
    }

    /**
     * @param array<LlmProviderResponse> $records
     * @return list<array{createdAt: int, sourceOrder: int, participant: string, searchable: string, text: string}>
     */
    private function loadAssistantItems(array $records): array
    {
        $items = [];

        foreach ($records as $record) {
            $message = $this->deserializeResponseMessage($record);

            if (!$message instanceof AssistantMessage) {
                continue;
            }

            $content = $message->content === null ? '' : trim($message->content);
            if ($content === '' || ($message->toolCalls ?? []) !== []) {
                continue;
            }

            $text = sprintf(
                "Assistant message\nFrom: bot\nSent at: %s\n\nText:\n%s",
                date('Y-m-d H:i:s', $record->createdAt),
                $content,
            );

            $items[] = [
                'createdAt' => $record->createdAt,
                'sourceOrder' => 1,
                'participant' => 'bot',
                'searchable' => mb_strtolower($text),
                'text' => $text,
            ];
        }

        return $items;
    }

    private function deserializeResponseMessage(LlmProviderResponse $record): MessageInterface
    {
        $message = $this->openaiSerializer->deserialize(
            serialized: $record->payload,
            to: $record->messageClass,
            tools: AgenticToolset::TOOLS,
        );

        if (!$message instanceof MessageInterface) {
            throw new \RuntimeException(sprintf(
                'Stored payload %s did not deserialize into %s.',
                $record->messageClass,
                MessageInterface::class,
            ));
        }

        return $message;
    }

    private function matchesUsername(?string $participant, ?string $username): bool
    {
        if ($username === null || $username === '') {
            return true;
        }

        $participant = $participant === null ? '' : ltrim(mb_strtolower($participant), '@');

        if ($participant === 'bot') {
            return self::isBotAlias($username);
        }

        return $participant === $username;
    }

    private static function isBotAlias(string $username): bool
    {
        $compact = preg_replace('/[^a-z0-9]+/', '', $username) ?? $username;

        return in_array($username, ['bot', 'assistant', 'wtf_happend_bot', 'wtf_happened_bot'], true)
            || in_array($compact, ['bot', 'assistant', 'wtfhappend', 'wtfhappendbot', 'wtfhappened', 'wtfhappenedbot'], true);
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

    /**
     * @param array{createdAt: int, participant: ?string, text: string} $item
     * @param list<string> $queryTokens
     */
    private function formatSearchResult(array $item, array $queryTokens): string
    {
        $participant = $item['participant'];
        if ($participant === null || $participant === '') {
            $participant = 'unknown';
        }

        return sprintf(
            "[%s] %s: %s",
            date('Y-m-d H:i:s', $item['createdAt']),
            $participant,
            $this->snippet($item['text'], $queryTokens),
        );
    }

    /**
     * @param list<string> $queryTokens
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
        $length = mb_strlen($text);
        $start = min($start, max(0, $length - self::MAX_SNIPPET_CHARS));
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
