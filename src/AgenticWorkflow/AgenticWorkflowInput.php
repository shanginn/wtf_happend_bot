<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

class AgenticWorkflowInput
{
    public function __construct(
        public int $chatId,
    ) {}
}
