<?php

declare(strict_types=1);

namespace Tests\Telegram;

use Bot\Telegram\TelegramTopicRouting;
use Phenogram\Bindings\Factories\MessageFactory;
use Tests\TestCase;

final class TelegramTopicRoutingTest extends TestCase
{
    public function testGenericReplyThreadIsNotATopic(): void
    {
        $message = MessageFactory::make(
            messageThreadId: 193132,
            isTopicMessage: null,
        );

        self::assertNull(TelegramTopicRouting::topicId($message));
    }

    public function testTelegramTopicMessageUsesItsThreadId(): void
    {
        $message = MessageFactory::make(
            messageThreadId: 42,
            isTopicMessage: true,
        );

        self::assertSame(42, TelegramTopicRouting::topicId($message));
    }
}
