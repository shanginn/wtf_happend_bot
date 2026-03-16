<?php

declare(strict_types=1);

namespace Bot\Telegram;

interface TelegramFileUrlResolverInterface
{
    public function resolve(string $fileId): ?string;
}
