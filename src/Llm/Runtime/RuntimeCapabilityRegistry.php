<?php

declare(strict_types=1);

namespace Bot\Llm\Runtime;

use Bot\Entity\RuntimeSkill;
use Bot\Entity\RuntimeTool;
use Bot\Llm\Skills\SkillInterface;
use Cycle\ORM\ORMInterface;
use Shanginn\Openai\ChatCompletion\CompletionRequest\ToolInterface;

final readonly class RuntimeCapabilityRegistry
{
    public function __construct(
        private ORMInterface $orm,
    ) {}

    /**
     * @param array<class-string<ToolInterface>|RuntimeToolDefinition> $tools
     * @return array<class-string<ToolInterface>|RuntimeToolDefinition>
     */
    public function responseToolsForChat(int $chatId, array $tools): array
    {
        return [
            ...$tools,
            ...$this->runtimeToolsForChat($chatId),
        ];
    }

    /**
     * Runtime skills may intentionally override a built-in skill with the same name.
     *
     * @param array<class-string<SkillInterface>|RuntimeSkillDefinition> $skills
     * @return array<class-string<SkillInterface>|RuntimeSkillDefinition>
     */
    public function responseSkillsForChat(int $chatId, array $skills): array
    {
        $runtimeSkills = $this->runtimeSkillsForChat($chatId);
        $runtimeSkillNames = array_fill_keys(
            array_map(static fn (RuntimeSkillDefinition $skill): string => $skill->name, $runtimeSkills),
            true,
        );

        $staticSkills = array_values(array_filter(
            $skills,
            static fn (string|RuntimeSkillDefinition $skill): bool => $skill instanceof RuntimeSkillDefinition
                || !isset($runtimeSkillNames[$skill::name()]),
        ));

        return [
            ...$staticSkills,
            ...$runtimeSkills,
        ];
    }

    /**
     * @return array<RuntimeSkillDefinition>
     */
    public function runtimeSkillsForChat(int $chatId, bool $enabledOnly = true): array
    {
        /** @var \Bot\Entity\RuntimeSkill\RuntimeSkillRepository $repo */
        $repo = $this->orm->getRepository(RuntimeSkill::class);

        return array_map(
            RuntimeSkillDefinition::fromEntity(...),
            $repo->findByChatId($chatId, $enabledOnly),
        );
    }

    /**
     * @return array<RuntimeToolDefinition>
     */
    public function runtimeToolsForChat(int $chatId, bool $enabledOnly = true): array
    {
        /** @var \Bot\Entity\RuntimeTool\RuntimeToolRepository $repo */
        $repo = $this->orm->getRepository(RuntimeTool::class);

        return array_map(
            RuntimeToolDefinition::fromEntity(...),
            $repo->findByChatId($chatId, $enabledOnly),
        );
    }
}
