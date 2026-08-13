<?php

declare(strict_types=1);

namespace Bot\Space\Runtime;

use InvalidArgumentException;

final readonly class SpaceIdentity
{
    public function __construct(
        public string $spaceId,
        public string $platform,
        public string $botInstanceId,
        public string $externalConversationId,
        public ?string $externalThreadId,
        public int $chatId,
        public string $chatType,
        public ?int $topicId,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{7,127}$/', $spaceId) !== 1) {
            throw new InvalidArgumentException('Space ID must be a stable URL-safe identifier.');
        }
        if (trim($platform) === '' || trim($botInstanceId) === '') {
            throw new InvalidArgumentException('Space platform and bot instance ID cannot be empty.');
        }
        if (trim($externalConversationId) === '') {
            throw new InvalidArgumentException('External conversation ID cannot be empty.');
        }
        if ($externalThreadId !== null && trim($externalThreadId) === '') {
            throw new InvalidArgumentException('External thread ID cannot be empty when present.');
        }
        if (!in_array($chatType, ['private', 'group', 'supergroup', 'channel'], true)) {
            throw new InvalidArgumentException('Telegram chat type is unsupported.');
        }
        if (($externalThreadId === null) !== ($topicId === null)) {
            throw new InvalidArgumentException('Telegram topic and external thread identity must agree.');
        }
        if ($platform === 'telegram' && ($externalThreadId !== null || $topicId !== null)) {
            throw new InvalidArgumentException(
                'Telegram Space identity is chat-scoped; topics are per-update routing metadata.',
            );
        }
    }

    /**
     * @return array<string, int|string|null>
     */
    public function metadata(): array
    {
        return [
            'spaceId'                => $this->spaceId,
            'platform'               => $this->platform,
            'botInstanceId'          => $this->botInstanceId,
            'externalConversationId' => $this->externalConversationId,
            'externalThreadId'       => $this->externalThreadId,
            'chatId'                 => $this->chatId,
            'chatType'               => $this->chatType,
            'topicId'                => $this->topicId,
        ];
    }
}
