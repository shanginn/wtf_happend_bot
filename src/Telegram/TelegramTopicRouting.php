<?php

declare(strict_types=1);

namespace Bot\Telegram;

use Phenogram\Bindings\Types\Interfaces\MessageInterface;

final class TelegramTopicRouting
{
    public static function topicId(?MessageInterface $message): ?int
    {
        if ($message?->isTopicMessage !== true) {
            return null;
        }

        return $message->messageThreadId;
    }
}
