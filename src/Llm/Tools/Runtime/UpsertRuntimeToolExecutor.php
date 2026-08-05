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
use JsonException;

final class UpsertRuntimeToolExecutor
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
        array $parametersSchema,
        string $instructions,
        bool $enabled = true,
    ): string {
        $name      = RuntimeCapabilityValidator::normalizeName($name);
        $nameError = RuntimeCapabilityValidator::nameError($name)
            ?? RuntimeCapabilityValidator::staticToolNameError($name);
        if ($nameError !== null) {
            return $nameError;
        }

        $description = trim($description);
        if ($description === '') {
            return 'Runtime tool description cannot be empty.';
        }
        $descriptionError = RuntimeCapabilityValidator::byteLimitError(
            $description,
            RuntimeCapabilityValidator::MAX_DESCRIPTION_BYTES,
            'Runtime tool description',
        );
        if ($descriptionError !== null) {
            return $descriptionError;
        }

        $instructions = trim($instructions);
        if ($instructions === '') {
            return 'Runtime tool instructions cannot be empty.';
        }
        $instructionsError = RuntimeCapabilityValidator::byteLimitError(
            $instructions,
            RuntimeCapabilityValidator::MAX_TOOL_INSTRUCTIONS_BYTES,
            'Runtime tool instructions',
        );
        if ($instructionsError !== null) {
            return $instructionsError;
        }

        $schemaError = RuntimeCapabilityValidator::parametersSchemaError($parametersSchema);
        if ($schemaError !== null) {
            return $schemaError;
        }

        try {
            $parametersSchema = RuntimeCapabilityValidator::encodeParametersSchema($parametersSchema);
        } catch (JsonException) {
            return 'parameters_schema could not be encoded as JSON.';
        }
        $schemaSizeError = RuntimeCapabilityValidator::byteLimitError(
            $parametersSchema,
            RuntimeCapabilityValidator::MAX_PARAMETERS_SCHEMA_BYTES,
            'parameters_schema',
        );
        if ($schemaSizeError !== null) {
            return $schemaSizeError;
        }

        return $this->mutationLock->synchronized(
            $chatId,
            function () use (
                $chatId,
                $description,
                $enabled,
                $instructions,
                $name,
                $parametersSchema,
            ): string {
                /** @var RuntimeToolRepository $repo */
                $repo    = $this->orm->getRepository(RuntimeTool::class);
                $tools   = $repo->findByChatId($chatId, false);
                $tool    = $repo->findByName($chatId, $name);
                $created = $tool === null;

                if ($created && count($tools) >= RuntimeCapabilityValidator::MAX_CAPABILITIES_PER_KIND) {
                    return sprintf(
                        'A chat can store at most %d runtime tools.',
                        RuntimeCapabilityValidator::MAX_CAPABILITIES_PER_KIND,
                    );
                }

                /** @var RuntimeSkillRepository $skillRepo */
                $skillRepo    = $this->orm->getRepository(RuntimeSkill::class);
                $enabledBytes = RuntimeCapabilityValidator::enabledBytes(
                    $skillRepo->findByChatId($chatId, false),
                    $tools,
                );
                if ($tool?->enabled) {
                    $enabledBytes -= RuntimeCapabilityValidator::storedRuntimeToolBytes($tool);
                }
                if ($enabled) {
                    $enabledBytes += RuntimeCapabilityValidator::runtimeToolBytes(
                        $name,
                        $description,
                        $parametersSchema,
                        $instructions,
                    );
                }

                if ($enabledBytes > RuntimeCapabilityValidator::MAX_ENABLED_BYTES_PER_CHAT) {
                    return sprintf(
                        'Enabled runtime capabilities would use %d bytes, exceeding the per-chat limit of %d bytes. Disable or shrink an existing capability first.',
                        $enabledBytes,
                        RuntimeCapabilityValidator::MAX_ENABLED_BYTES_PER_CHAT,
                    );
                }

                if ($tool === null) {
                    $tool = new RuntimeTool(
                        chatId: $chatId,
                        name: $name,
                        description: $description,
                        parametersSchema: $parametersSchema,
                        instructions: $instructions,
                        enabled: $enabled,
                    );
                } else {
                    $tool->description      = $description;
                    $tool->parametersSchema = $parametersSchema;
                    $tool->instructions     = $instructions;
                    $tool->enabled          = $enabled;
                    $tool->touch();
                }

                $repo->save($tool);

                return sprintf(
                    'Runtime tool "%s" %s and is %s.',
                    $name,
                    $created ? 'created' : 'updated',
                    $tool->enabled ? 'enabled' : 'disabled',
                );
            },
        );
    }
}
