<?php

declare(strict_types=1);

namespace Bot\Space\Runtime;

use Bot\Space\Persistence\SpaceBindingKey;
use Bot\Space\Persistence\SpaceId;
use Bot\Space\Persistence\SpaceReleaseSeed;
use Bot\Space\Persistence\SpaceStore;
use Bot\Telegram\TelegramTopicRouting;
use Bot\Telegram\Update;
use InvalidArgumentException;
use LogicException;

/**
 * Deterministic bootstrap resolver. A persistence-backed resolver may later
 * implement the same contract without changing workflow identity or history.
 */
final readonly class TelegramSpaceIdentityResolver implements SpaceIdentityResolverInterface
{
    public function __construct(
        private string $botInstanceId,
        private ?SpaceStore $store = null,
        private ?SpaceReleaseSeed $initialRelease = null,
    ) {
        if (trim($this->botInstanceId) === '') {
            throw new InvalidArgumentException('Bot instance ID cannot be empty.');
        }
    }

    public function resolve(Update $update): SpaceIdentity
    {
        $chat = $update->effectiveChat
            ?? throw new InvalidArgumentException(
                'Cannot resolve a Space for a Telegram update without a chat.',
            );
        $topicId                = TelegramTopicRouting::topicId($update->effectiveMessage);
        $externalConversationId = (string) $chat->id;
        $externalThreadId       = $topicId === null ? null : (string) $topicId;
        $binding                = new SpaceBindingKey(
            botInstanceId: $this->botInstanceId,
            platform: 'telegram',
            externalConversationId: $externalConversationId,
            externalThreadId: $externalThreadId,
        );

        $chatType = $chat->type;
        if (!is_string($chatType)) {
            throw new LogicException('Telegram chat type is unavailable.');
        }

        $spaceId = $this->store === null
            ? SpaceId::forBinding($binding)
            : $this->store->resolveOrCreate(
                $binding,
                $this->initialRelease
                    ?? throw new LogicException('A persistent Space resolver requires an initial release.'),
            )->spaceId;

        return new SpaceIdentity(
            spaceId: $spaceId,
            platform: 'telegram',
            botInstanceId: $this->botInstanceId,
            externalConversationId: $externalConversationId,
            externalThreadId: $externalThreadId,
            chatId: $chat->id,
            chatType: $chatType,
            topicId: $topicId,
        );
    }
}
