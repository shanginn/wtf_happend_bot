<?php

declare(strict_types=1);

namespace Bot\Space\Runtime;

use InvalidArgumentException;

/**
 * One immutable Space skill pinned into a runtime snapshot. The full body is
 * kept out of the always-on system prompt and is injected only after the host
 * response gate selects the skill for the current batch.
 */
final readonly class SpaceSkillDefinition
{
    public function __construct(
        public string $name,
        public string $description,
        public string $body,
    ) {
        if (trim($name) === '' || trim($description) === '' || trim($body) === '') {
            throw new InvalidArgumentException(
                'Space skill name, description, and body must be non-empty.',
            );
        }
    }
}
