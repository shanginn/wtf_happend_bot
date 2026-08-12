<?php

declare(strict_types=1);

namespace Bot\Space\Workflow;

use Bot\Telegram\Update;
use InvalidArgumentException;

final readonly class QueuedSpaceUpdate
{
    public function __construct(
        public Update $update,
        public bool $appendToAgent,
        public string $ingestionId,
    ) {
        if (trim($ingestionId) === '') {
            throw new InvalidArgumentException('Queued Space update ingestion ID cannot be empty.');
        }
    }
}
