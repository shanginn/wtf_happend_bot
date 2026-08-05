<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Telegram\Update;
use InvalidArgumentException;

final readonly class QueuedTelegramUpdate
{
    public function __construct(
        public Update $update,
        public bool $appendToAgent,
        public string $ingestionId,
    ) {
        if ($ingestionId === '') {
            throw new InvalidArgumentException('Queued Telegram update ingestion ID cannot be empty.');
        }
    }
}
