<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Memory;

use Bot\Memory\ParticipantMemoryStore;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'RecallMemoryExecutor.')]
class RecallMemoryExecutor
{
    public function __construct(
        private readonly ParticipantMemoryStore $memoryStore,
    ) {}

    #[ActivityMethod]
    public function execute(int $chatId, RecallMemory $schema): string
    {
        return $this->memoryStore->recall(
            chatId: $chatId,
            query: $schema,
        );
    }
}
