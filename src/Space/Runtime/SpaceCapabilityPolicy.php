<?php

declare(strict_types=1);

namespace Bot\Space\Runtime;

use InvalidArgumentException;
use JsonException;

/**
 * Immutable authority boundary for every Space release.
 *
 * Personalities, prompt overlays, memories, skills, and capsules may evolve;
 * this host-owned policy cannot be expanded by a Space or by its Dream.
 */
final class SpaceCapabilityPolicy
{
    public const string JSON = '{"version":1,"capsuleNetwork":"deny","crossSpaceReads":false}';

    public static function assertFixed(string $json): void
    {
        try {
            $policy = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Space capability policy must be valid JSON.', previous: $error);
        }
        if ($policy !== json_decode(self::JSON, true, flags: \JSON_THROW_ON_ERROR)) {
            throw new InvalidArgumentException('A Space release cannot change the immutable host capability policy.');
        }
    }
}
