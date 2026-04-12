<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Memory;

use Bot\Memory\ParticipantMemoryStore;
use Phenogram\Bindings\ApiInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'SaveMemoryExecutor.')]
class SaveMemoryExecutor
{
    public function __construct(
        private readonly ParticipantMemoryStore $memoryStore,
        private readonly ApiInterface $api,
    ) {}

    #[ActivityMethod]
    public function execute(int $chatId, SaveMemory $schema, ?int $topicId = null): string
    {
        $result = $this->memoryStore->save(
            chatId: $chatId,
            memory: $schema,
        );

        if (str_starts_with($result, 'Memory saved') || str_starts_with($result, 'Memory updated')) {
            $this->api->sendMessage(
                chatId: $chatId,
                text: 'Память обновлена',
                messageThreadId: $topicId,
            );
        }

        return $result;
    }
}
