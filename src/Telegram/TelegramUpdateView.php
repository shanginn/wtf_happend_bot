<?php

declare(strict_types=1);

namespace Bot\Telegram;

final readonly class TelegramUpdateView
{
    /**
     * @param list<string> $imageUrls
     */
    public function __construct(
        public string $text,
        public ?string $participantReference = null,
        public array $imageUrls = [],
    ) {}
}
