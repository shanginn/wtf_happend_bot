<?php

declare(strict_types=1);

namespace Bot\Telegram;

final readonly class InvoiceWorkflowRoute
{
    public function __construct(
        public int $chatId,
        public ?string $originalPayload = null,
        public ?string $payloadHash = null,
    ) {}
}
