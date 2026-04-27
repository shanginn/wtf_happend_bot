<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

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
    ) {}
}
