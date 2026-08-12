<?php

declare(strict_types=1);

namespace Bot\Space\Persistence;

use InvalidArgumentException;

final class SpaceId
{
    private const string OPAQUE_ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{7,127}$/';

    public static function new(): string
    {
        return 'spc_' . bin2hex(random_bytes(20));
    }

    public static function forBinding(SpaceBindingKey $binding): string
    {
        $canonical = implode("\0", [
            'space-v1',
            $binding->platform,
            $binding->botInstanceId,
            $binding->externalConversationId,
            $binding->externalThreadId,
        ]);

        return 'spc_' . substr(hash('sha256', $canonical), 0, 40);
    }

    public static function assert(string $id): string
    {
        if (preg_match(self::OPAQUE_ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException('Space identifiers must be opaque URL-safe identifiers.');
        }

        return $id;
    }
}
