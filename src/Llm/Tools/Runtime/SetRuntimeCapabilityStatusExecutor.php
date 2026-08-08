<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Runtime;

use Bot\Entity\RuntimeSkill;
use Bot\Entity\RuntimeSkill\RuntimeSkillRepository;
use Bot\Entity\RuntimeTool;
use Bot\Entity\RuntimeTool\RuntimeToolRepository;
use Bot\Llm\Runtime\RuntimeCapabilityMutationLock;
use Bot\Llm\Runtime\RuntimeCapabilityValidator;
use Cycle\ORM\ORMInterface;

final class SetRuntimeCapabilityStatusExecutor
{
    private readonly RuntimeCapabilityMutationLock $mutationLock;

    public function __construct(
        private readonly ORMInterface $orm,
        ?RuntimeCapabilityMutationLock $mutationLock = null,
    ) {
        $this->mutationLock = $mutationLock ?? new RuntimeCapabilityMutationLock($orm);
    }

    public function execute(int $chatId, string $kind, string $name, bool $enabled): string
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, ['skill', 'tool'], true)) {
            return 'Unknown capability kind. Use "skill" or "tool".';
        }

        $name      = RuntimeCapabilityValidator::normalizeName($name);
        $nameError = RuntimeCapabilityValidator::nameError($name);
        if ($nameError !== null) {
            return $nameError;
        }

        return $this->mutationLock->synchronized(
            $chatId,
            fn (): string => $kind === 'skill'
                ? $this->setSkillStatus($chatId, $name, $enabled)
                : $this->setToolStatus($chatId, $name, $enabled),
        );
    }

    private static function budgetError(int $enabledBytes): string
    {
        return sprintf(
            'Enabled runtime capabilities would use %d bytes, exceeding the per-chat limit of %d bytes. Disable or shrink an existing capability first.',
            $enabledBytes,
            RuntimeCapabilityValidator::MAX_ENABLED_BYTES_PER_CHAT,
        );
    }

    private function setSkillStatus(int $chatId, string $name, bool $enabled): string
    {
        /** @var RuntimeSkillRepository $repo */
        $repo  = $this->orm->getRepository(RuntimeSkill::class);
        $skill = $repo->findByName($chatId, $name);

        if ($skill === null) {
            return sprintf('Runtime skill "%s" was not found.', $name);
        }

        if ($enabled && !$skill->enabled) {
            $storageError = RuntimeCapabilityValidator::storedRuntimeSkillError($skill);
            if ($storageError !== null) {
                return sprintf(
                    'Runtime skill "%s" cannot be enabled: %s',
                    $name,
                    $storageError,
                );
            }

            /** @var RuntimeToolRepository $toolRepo */
            $toolRepo     = $this->orm->getRepository(RuntimeTool::class);
            $enabledBytes = RuntimeCapabilityValidator::enabledBytes(
                $repo->findByChatId($chatId, false),
                $toolRepo->findByChatId($chatId, false),
            ) + RuntimeCapabilityValidator::storedRuntimeSkillBytes($skill);

            if ($enabledBytes > RuntimeCapabilityValidator::MAX_ENABLED_BYTES_PER_CHAT) {
                return self::budgetError($enabledBytes);
            }
        }

        $skill->enabled = $enabled;
        $skill->touch();
        $repo->save($skill);

        return sprintf('Runtime skill "%s" is now %s.', $name, $skill->enabled ? 'enabled' : 'disabled');
    }

    private function setToolStatus(int $chatId, string $name, bool $enabled): string
    {
        /** @var RuntimeToolRepository $repo */
        $repo = $this->orm->getRepository(RuntimeTool::class);
        $tool = $repo->findByName($chatId, $name);

        if ($tool === null) {
            return sprintf('Runtime tool "%s" was not found.', $name);
        }

        if ($enabled && !$tool->enabled) {
            $storageError = RuntimeCapabilityValidator::storedRuntimeToolError($tool);
            if ($storageError !== null) {
                return sprintf(
                    'Runtime tool "%s" cannot be enabled: %s',
                    $name,
                    $storageError,
                );
            }

            /** @var RuntimeSkillRepository $skillRepo */
            $skillRepo    = $this->orm->getRepository(RuntimeSkill::class);
            $enabledBytes = RuntimeCapabilityValidator::enabledBytes(
                $skillRepo->findByChatId($chatId, false),
                $repo->findByChatId($chatId, false),
            ) + RuntimeCapabilityValidator::storedRuntimeToolBytes($tool);

            if ($enabledBytes > RuntimeCapabilityValidator::MAX_ENABLED_BYTES_PER_CHAT) {
                return self::budgetError($enabledBytes);
            }
        }

        $tool->enabled = $enabled;
        $tool->touch();
        $repo->save($tool);

        return sprintf('Runtime tool "%s" is now %s.', $name, $tool->enabled ? 'enabled' : 'disabled');
    }
}
