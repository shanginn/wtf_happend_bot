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

final class UpsertRuntimeSkillExecutor
{
    private readonly RuntimeCapabilityMutationLock $mutationLock;

    public function __construct(
        private readonly ORMInterface $orm,
        ?RuntimeCapabilityMutationLock $mutationLock = null,
    ) {
        $this->mutationLock = $mutationLock ?? new RuntimeCapabilityMutationLock($orm);
    }

    public function execute(
        int $chatId,
        string $name,
        string $description,
        string $body,
        bool $enabled = true,
    ): string {
        $name      = RuntimeCapabilityValidator::normalizeName($name);
        $nameError = RuntimeCapabilityValidator::nameError($name);
        if ($nameError !== null) {
            return $nameError;
        }

        $description = trim($description);
        if ($description === '') {
            return 'Skill description cannot be empty.';
        }
        $descriptionError = RuntimeCapabilityValidator::byteLimitError(
            $description,
            RuntimeCapabilityValidator::MAX_DESCRIPTION_BYTES,
            'Skill description',
        );
        if ($descriptionError !== null) {
            return $descriptionError;
        }

        $body = trim($body);
        if ($body === '') {
            return 'Skill body cannot be empty.';
        }
        $bodyError = RuntimeCapabilityValidator::byteLimitError(
            $body,
            RuntimeCapabilityValidator::MAX_SKILL_BODY_BYTES,
            'Skill body',
        );
        if ($bodyError !== null) {
            return $bodyError;
        }

        return $this->mutationLock->synchronized(
            $chatId,
            function () use ($body, $chatId, $description, $enabled, $name): string {
                /** @var RuntimeSkillRepository $repo */
                $repo    = $this->orm->getRepository(RuntimeSkill::class);
                $skills  = $repo->findByChatId($chatId, false);
                $skill   = $repo->findByName($chatId, $name);
                $created = $skill === null;

                if ($created && count($skills) >= RuntimeCapabilityValidator::MAX_CAPABILITIES_PER_KIND) {
                    return sprintf(
                        'A chat can store at most %d runtime skills.',
                        RuntimeCapabilityValidator::MAX_CAPABILITIES_PER_KIND,
                    );
                }

                /** @var RuntimeToolRepository $toolRepo */
                $toolRepo     = $this->orm->getRepository(RuntimeTool::class);
                $enabledBytes = RuntimeCapabilityValidator::enabledBytes(
                    $skills,
                    $toolRepo->findByChatId($chatId, false),
                );
                if ($skill?->enabled) {
                    $enabledBytes -= RuntimeCapabilityValidator::storedRuntimeSkillBytes($skill);
                }
                if ($enabled) {
                    $enabledBytes += RuntimeCapabilityValidator::runtimeSkillBytes(
                        $name,
                        $description,
                        $body,
                    );
                }

                if ($enabledBytes > RuntimeCapabilityValidator::MAX_ENABLED_BYTES_PER_CHAT) {
                    return sprintf(
                        'Enabled runtime capabilities would use %d bytes, exceeding the per-chat limit of %d bytes. Disable or shrink an existing capability first.',
                        $enabledBytes,
                        RuntimeCapabilityValidator::MAX_ENABLED_BYTES_PER_CHAT,
                    );
                }

                if ($skill === null) {
                    $skill = new RuntimeSkill(
                        chatId: $chatId,
                        name: $name,
                        description: $description,
                        body: $body,
                        enabled: $enabled,
                    );
                } else {
                    $skill->description = $description;
                    $skill->body        = $body;
                    $skill->enabled     = $enabled;
                    $skill->touch();
                }

                $repo->save($skill);

                return sprintf(
                    'Runtime skill "%s" %s and is %s.',
                    $name,
                    $created ? 'created' : 'updated',
                    $skill->enabled ? 'enabled' : 'disabled',
                );
            },
        );
    }
}
