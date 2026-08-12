<?php

declare(strict_types=1);

namespace Tests\Space\Workflow;

use Bot\Space\Runtime\TelegramSpaceIdentityResolver;
use Bot\Telegram\Update;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use Tests\TestCase;

final class TelegramSpaceIdentityResolverTest extends TestCase
{
    public function testIdentityIsStableAndSeparatesBotChatAndTopic(): void
    {
        $root          = self::update();
        $zeroTopic     = self::update(0);
        $genericThread = self::update(193132);
        $topic         = self::update(42, true);

        $resolver  = new TelegramSpaceIdentityResolver('primary-bot');
        $rootSpace = $resolver->resolve($root);

        self::assertSame($rootSpace->spaceId, $resolver->resolve($root)->spaceId);
        self::assertSame($rootSpace->spaceId, $resolver->resolve($zeroTopic)->spaceId);
        self::assertSame($rootSpace->spaceId, $resolver->resolve($genericThread)->spaceId);
        self::assertNotSame($rootSpace->spaceId, $resolver->resolve($topic)->spaceId);
        self::assertNotSame(
            $rootSpace->spaceId,
            (new TelegramSpaceIdentityResolver('another-bot'))->resolve($root)->spaceId,
        );
        self::assertSame('telegram', $rootSpace->platform);
        self::assertSame('-100123456', $rootSpace->externalConversationId);
        self::assertNull($rootSpace->externalThreadId);
        self::assertNull($resolver->resolve($zeroTopic)->externalThreadId);
        self::assertNull($resolver->resolve($zeroTopic)->topicId);
        self::assertNull($resolver->resolve($genericThread)->externalThreadId);
        self::assertNull($resolver->resolve($genericThread)->topicId);
        self::assertSame('42', $resolver->resolve($topic)->externalThreadId);
    }

    private static function update(
        ?int $messageThreadId = null,
        ?bool $isTopicMessage = null,
    ): Update {
        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: -100123456, type: 'supergroup'),
                messageThreadId: $messageThreadId,
                isTopicMessage: $isTopicMessage,
            ),
        );
        assert($update instanceof Update);

        return $update;
    }
}
