<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Memory;

use Bot\Memory\ParticipantMemoryStore;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'SaveMemoryExecutor.')]
class SaveMemoryExecutor
{
    public function __construct(
        private readonly ParticipantMemoryStore $memoryStore,
    ) {}

    #[ActivityMethod]
    public function execute(int $chatId, SaveMemory $schema): string
    {
        return $this->memoryStore->save(
            chatId: $chatId,
            memory: $schema,
        );
    }
}
