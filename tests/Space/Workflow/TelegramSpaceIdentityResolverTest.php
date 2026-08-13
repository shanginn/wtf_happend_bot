<?php

declare(strict_types=1);

namespace Tests\Space\Workflow;

use Bot\Space\Runtime\TelegramSpaceIdentityResolver;
use Bot\Space\Runtime\SpaceIdentity;
use Bot\Telegram\Update;
use InvalidArgumentException;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use Tests\TestCase;

final class TelegramSpaceIdentityResolverTest extends TestCase
{
    public function testIdentityIsStablePerBotAndChatRegardlessOfTopic(): void
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
        self::assertSame($rootSpace->spaceId, $resolver->resolve($topic)->spaceId);
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
        self::assertNull($resolver->resolve($topic)->externalThreadId);
        self::assertNull($resolver->resolve($topic)->topicId);
    }

    public function testTelegramTopicCannotBeEmbeddedInSpaceIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('topics are per-update routing metadata');

        new SpaceIdentity(
            spaceId: 'spc_0123456789abcdef0123456789abcdef01234567',
            platform: 'telegram',
            botInstanceId: 'primary-bot',
            externalConversationId: '-100123456',
            externalThreadId: '42',
            chatId: -100123456,
            chatType: 'supergroup',
            topicId: 42,
        );
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
