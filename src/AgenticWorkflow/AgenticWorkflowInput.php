<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Telegram\Update;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Temporal\Internal\Marshaller\Meta\MarshalArray;

class AgenticWorkflowInput
{
    public function __construct(
        public int $chatId,
        public int $processedCount = 0,
        #[MarshalArray(of: MessageInterface::class)]
        public array $workingMemory = [],
        public string $compactedContext = '',
        public int $lastActivityAt = 0,
        public int $lastCompactionAt = 0,
        public int $compactionRetryAfter = 0,
        public int $consecutiveCompactionFailures = 0,
        public int $pipelinePendingSince = 0,
        #[MarshalArray(of: Update::class)]
        public array $pendingUpdates = [],
    ) {}

    public function getPendingUpdates(): array
    {
        return isset($this->pendingUpdates) ? $this->pendingUpdates : [];
    }
}
