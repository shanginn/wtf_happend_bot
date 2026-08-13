<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Chat;

final readonly class BotMessageHistoryItem
{
    public function __construct(
        public int $createdAt,
        public string $text,
        public int $sourceOrder,
    ) {}
}
