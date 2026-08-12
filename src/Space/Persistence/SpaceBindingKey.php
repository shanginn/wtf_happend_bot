<?php

declare(strict_types=1);

namespace Bot\Space\Persistence;

use InvalidArgumentException;

final readonly class SpaceBindingKey
{
    public string $botInstanceId;
    public string $platform;
    public string $externalConversationId;
    public string $externalThreadId;

    public function __construct(
        string $botInstanceId,
        string $platform,
        int|string $externalConversationId,
        null|int|string $externalThreadId = null,
    ) {
        $this->botInstanceId          = self::required($botInstanceId, 'bot instance ID');
        $this->platform               = strtolower(self::required($platform, 'platform'));
        $this->externalConversationId = self::required(
            (string) $externalConversationId,
            'external conversation ID',
        );
        $this->externalThreadId = self::normalizeThreadId($externalThreadId);
    }

    public static function normalizeThreadId(null|int|string $threadId): string
    {
        if ($threadId === null) {
            return '';
        }

        $normalized = trim((string) $threadId);

        return $normalized === '0' ? '' : $normalized;
    }

    public function isRoot(): bool
    {
        return $this->externalThreadId === '';
    }

    public function canonical(): string
    {
        return implode("\x1f", [
            $this->botInstanceId,
            $this->platform,
            $this->externalConversationId,
            $this->externalThreadId,
        ]);
    }

    private static function required(string $value, string $label): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('%s must not be empty.', ucfirst($label)));
        }

        return $value;
    }
}
