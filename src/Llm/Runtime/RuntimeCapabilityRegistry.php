<?php

declare(strict_types=1);

namespace Bot\Llm\Runtime;

use Bot\Entity\RuntimeSkill;
use Bot\Entity\RuntimeSkill\RuntimeSkillRepository;
use Cycle\ORM\ORMInterface;

final readonly class RuntimeCapabilityRegistry
{
    public function __construct(
        private ORMInterface $orm,
    ) {}

    /**
     * @param int  $chatId
     * @param bool $enabledOnly
     *
     * @return array<RuntimeSkillDefinition>
     */
    public function runtimeSkillsForChat(int $chatId, bool $enabledOnly = true): array
    {
        /** @var RuntimeSkillRepository $repo */
        $repo = $this->orm->getRepository(RuntimeSkill::class);

        $definitions = [];
        $bytes       = 0;
        foreach ($repo->findByChatId($chatId, $enabledOnly) as $skill) {
            if (RuntimeCapabilityValidator::storedRuntimeSkillError($skill) !== null) {
                continue;
            }

            $skillBytes = RuntimeCapabilityValidator::storedRuntimeSkillBytes($skill);
            if ($bytes + $skillBytes > RuntimeCapabilityValidator::MAX_ENABLED_BYTES_PER_CHAT) {
                continue;
            }

            $definitions[] = RuntimeSkillDefinition::fromEntity($skill);
            $bytes += $skillBytes;
        }

        return $definitions;
    }
}
