<?php

declare(strict_types=1);

namespace Bot\Telegram;

final readonly class InputMessageView
{
    public function __construct(
        public string $text,
        public ?string $participantReference = null,
        public int $imageAttachmentCount = 0,
        public ?int $updateId = null,
        public ?string $memoryEvidenceText = null,
        public ?int $messageTimestamp = null,
        public ?string $directHistoryText = null,
    ) {}
}
