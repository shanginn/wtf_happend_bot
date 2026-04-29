<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Runtime;

use Bot\Entity\RuntimeSkill;
use Bot\Entity\RuntimeTool;
use Bot\Llm\Runtime\RuntimeCapabilityValidator;
use Cycle\ORM\ORMInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'ListRuntimeCapabilitiesExecutor.')]
class ListRuntimeCapabilitiesExecutor
{
    public function __construct(
        private readonly ORMInterface $orm,
    ) {}

    #[ActivityMethod]
    public function execute(int $chatId, ListRuntimeCapabilities $schema): string
    {
        $kind = strtolower(trim($schema->kind));
        if (!in_array($kind, ['all', 'skill', 'tool'], true)) {
            return 'Unknown capability kind. Use "all", "skill", or "tool".';
        }

        $payload = [
            'chat_id' => $chatId,
            'skills' => [],
            'tools' => [],
            'static_skill_names' => RuntimeCapabilityValidator::staticSkillNames(),
            'static_tool_names' => RuntimeCapabilityValidator::staticToolNames(),
        ];

        if ($kind === 'all' || $kind === 'skill') {
            /** @var \Bot\Entity\RuntimeSkill\RuntimeSkillRepository $repo */
            $repo = $this->orm->getRepository(RuntimeSkill::class);
            $payload['skills'] = array_map(
                static fn (RuntimeSkill $skill): array => [
                    'name' => $skill->name,
                    'description' => $skill->description,
                    'enabled' => $skill->enabled,
                    'updated_at' => $skill->updatedAt,
                ],
                $repo->findByChatId($chatId, !$schema->includeDisabled),
            );
        }

        if ($kind === 'all' || $kind === 'tool') {
            /** @var \Bot\Entity\RuntimeTool\RuntimeToolRepository $repo */
            $repo = $this->orm->getRepository(RuntimeTool::class);
            $payload['tools'] = array_map(
                static fn (RuntimeTool $tool): array => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters_schema' => RuntimeCapabilityValidator::decodeParametersSchema($tool->parametersSchema),
                    'enabled' => $tool->enabled,
                    'updated_at' => $tool->updatedAt,
                ],
                $repo->findByChatId($chatId, !$schema->includeDisabled),
            );
        }

        return json_encode(
            $payload,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }
}
