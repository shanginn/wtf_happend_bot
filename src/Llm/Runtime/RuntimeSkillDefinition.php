<?php

declare(strict_types=1);

namespace Bot\Llm\Runtime;

use Bot\Entity\RuntimeSkill;

final readonly class RuntimeSkillDefinition
{
    public function __construct(
        public string $name,
        public string $description,
        public string $body,
    ) {}

    public static function fromEntity(RuntimeSkill $skill): self
    {
        return new self(
            name: $skill->name,
            description: $skill->description,
            body: $skill->body,
        );
    }
}
