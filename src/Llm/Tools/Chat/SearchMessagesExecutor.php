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

        $limit = max(1, min($schema->limit, 300));
        $query = mb_strtolower(trim($schema->query));
        $username = $schema->username === null ? null : ltrim(mb_strtolower(trim($schema->username)), '@');
        $window = $query === '' ? max($limit, 50) : 300;

        $items = [
            ...$this->loadUpdateItems($updateRepo->findLastN($chatId, $window)),
            ...$this->loadAssistantItems($responseRepo->findLastNByChat($chatId, LlmProviderType::Openai, $window)),
        ];

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

        $selected = array_column(array_slice($matches, -$limit), 'text');

        $header = $query === '' ? 'Recent chat history' : 'Relevant chat history';

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

        foreach (preg_split('/\s+/', $query) ?: [] as $token) {
            if ($token !== '' && !str_contains($searchable, $token)) {
                return false;
            }
        }

        return true;
    }
}
