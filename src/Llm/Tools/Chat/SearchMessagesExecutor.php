<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Chat;

use Bot\Entity\UpdateRecord;
use Bot\Telegram\Factory;
use Bot\Telegram\TelegramUpdateViewFactory;
use Bot\Telegram\TelegramUpdateViewFactoryInterface;
use Cycle\ORM\ORMInterface;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\SerializerInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'SearchMessagesExecutor.')]
class SearchMessagesExecutor
{
    private readonly SerializerInterface $telegramSerializer;
    private readonly TelegramUpdateViewFactoryInterface $updateViewFactory;

    public function __construct(
        private readonly ORMInterface $orm,
        ?SerializerInterface $telegramSerializer = null,
        ?TelegramUpdateViewFactoryInterface $updateViewFactory = null,
    ) {
        $this->telegramSerializer = $telegramSerializer ?? new Serializer(new Factory());
        $this->updateViewFactory = $updateViewFactory ?? new TelegramUpdateViewFactory();
    }

    #[ActivityMethod]
    public function execute(int $chatId, SearchMessages $schema): string
    {
        /** @var \Bot\Entity\UpdateRecord\UpdateRecordRepository $repo */
        $repo = $this->orm->getRepository(UpdateRecord::class);

        $limit = max(1, min($schema->limit, 30));
        $query = mb_strtolower(trim($schema->query));
        $username = $schema->username === null ? null : ltrim(mb_strtolower(trim($schema->username)), '@');
        $window = $query === '' ? max($limit, 50) : 300;

        $records = array_reverse($repo->findLastN($chatId, $window));

        $matches = [];

        foreach ($records as $record) {
            $decoded = json_decode($record->update, true, flags: \JSON_THROW_ON_ERROR);
            $update = $this->telegramSerializer->deserialize($decoded, UpdateInterface::class);
            $view = $this->updateViewFactory->create($update);

            if ($username !== null) {
                $participant = $view->participantReference === null
                    ? ''
                    : ltrim(mb_strtolower($view->participantReference), '@');

                if ($participant !== $username) {
                    continue;
                }
            }

            if ($query !== '') {
                $searchable = mb_strtolower($view->text);
                $matched = true;

                foreach (preg_split('/\s+/', $query) ?: [] as $token) {
                    if ($token !== '' && !str_contains($searchable, $token)) {
                        $matched = false;
                        break;
                    }
                }

                if (!$matched) {
                    continue;
                }
            }

            $matches[] = $view->text;
        }

        if ($matches === []) {
            if ($query === '') {
                return 'No recent messages found in chat history.';
            }

            return 'No messages found matching "' . $schema->query . '"'
                . ($schema->username === null ? '' : ' for ' . $schema->username)
                . '.';
        }

        $selected = array_slice($matches, -$limit);

        $header = $query === '' ? 'Recent chat history' : 'Relevant chat history';

        return $header . "\n\n" . implode("\n\n---\n\n", $selected);
    }
}
