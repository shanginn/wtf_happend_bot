<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Runtime;

use Bot\Entity\RuntimeSkill;
use Bot\Entity\RuntimeTool;
use Bot\Llm\Runtime\RuntimeCapabilityValidator;
use Cycle\ORM\ORMInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'SetRuntimeCapabilityStatusExecutor.')]
class SetRuntimeCapabilityStatusExecutor
{
    public function __construct(
        private readonly ORMInterface $orm,
    ) {}

    #[ActivityMethod]
    public function execute(int $chatId, SetRuntimeCapabilityStatus $schema): string
    {
        $kind = strtolower(trim($schema->kind));
        if (!in_array($kind, ['skill', 'tool'], true)) {
            return 'Unknown capability kind. Use "skill" or "tool".';
        }

        $name = RuntimeCapabilityValidator::normalizeName($schema->name);
        $nameError = RuntimeCapabilityValidator::nameError($name);
        if ($nameError !== null) {
            return $nameError;
        }

        if ($kind === 'skill') {
            /** @var \Bot\Entity\RuntimeSkill\RuntimeSkillRepository $repo */
            $repo = $this->orm->getRepository(RuntimeSkill::class);
            $skill = $repo->findByName($chatId, $name);

            if ($skill === null) {
                return sprintf('Runtime skill "%s" was not found.', $name);
            }

            $skill->enabled = $schema->enabled;
            $skill->touch();
            $repo->save($skill);

            return sprintf('Runtime skill "%s" is now %s.', $name, $skill->enabled ? 'enabled' : 'disabled');
        }

        /** @var \Bot\Entity\RuntimeTool\RuntimeToolRepository $repo */
        $repo = $this->orm->getRepository(RuntimeTool::class);
        $tool = $repo->findByName($chatId, $name);

        if ($tool === null) {
            return sprintf('Runtime tool "%s" was not found.', $name);
        }

        $tool->enabled = $schema->enabled;
        $tool->touch();
        $repo->save($tool);

        return sprintf('Runtime tool "%s" is now %s.', $name, $tool->enabled ? 'enabled' : 'disabled');
    }
}
