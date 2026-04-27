<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Memory;

use Bot\Memory\ParticipantMemoryStore;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'ForgetMemoryExecutor.')]
class ForgetMemoryExecutor
{
    public function __construct(
        private readonly ParticipantMemoryStore $memoryStore,
    ) {}

    #[ActivityMethod]
    public function execute(int $chatId, ForgetMemory $schema): string
    {
        return $this->memoryStore->forget(
            chatId: $chatId,
            request: $schema,
        );
    }
}
