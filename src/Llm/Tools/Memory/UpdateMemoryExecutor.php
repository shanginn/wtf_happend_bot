<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Memory;

use Bot\Memory\ParticipantMemoryStore;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'UpdateMemoryExecutor.')]
class UpdateMemoryExecutor
{
    public function __construct(
        private readonly ParticipantMemoryStore $memoryStore,
    ) {}

    #[ActivityMethod]
    public function execute(int $chatId, UpdateMemory $schema): string
    {
        return $this->memoryStore->update(
            chatId: $chatId,
            request: $schema,
        );
    }
}
