<?php

declare(strict_types=1);

namespace Bot\Space\Persistence;

/**
 * Cycle binds untyped raw-SQL parameters as strings. PHP false would therefore
 * become an empty string, which PostgreSQL rejects for boolean columns. The
 * integer forms remain unambiguous for both PostgreSQL and SQLite.
 */
final class SqlBoolean
{
    public static function encode(bool $value): int
    {
        return $value ? 1 : 0;
    }
}
