<?php

declare(strict_types=1);

namespace Bot\RouterWorkflow;

class RouterWorkflowInput
{
    public function __construct(
        public int $chatId,
        public ?int $messageThreadId = null,
        public int $processedCount = 0,
        public array $summarizedHistory = [],
    ) {}
}
