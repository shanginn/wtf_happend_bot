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
    public function execute(int $chatId, SaveMemory $schema): string
    {
        $result = $this->memoryStore->save(
            chatId: $chatId,
            memory: $schema,
        );

        if (!str_starts_with($result, 'Memory not saved:')) {
            $this->api->sendMessage($chatId, 'Память обновлена');
        }

        return $result;
    }
}
