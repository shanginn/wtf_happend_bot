<?php

declare(strict_types=1);

namespace Bot\Space\Sandbox;

/** Canonical immutable Gondolin guest-image identity used across trust boundaries. */
final class GondolinImageBuildId
{
    private const string PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D';

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }
}
