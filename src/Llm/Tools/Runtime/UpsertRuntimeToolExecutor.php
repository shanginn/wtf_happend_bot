<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Runtime;

use Bot\Entity\RuntimeTool;
use Bot\Llm\Runtime\RuntimeCapabilityValidator;
use Cycle\ORM\ORMInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'UpsertRuntimeToolExecutor.')]
class UpsertRuntimeToolExecutor
{
    public function __construct(
        private readonly ORMInterface $orm,
    ) {}

    #[ActivityMethod]
    public function execute(int $chatId, UpsertRuntimeTool $schema): string
    {
        $name = RuntimeCapabilityValidator::normalizeName($schema->name);
        $nameError = RuntimeCapabilityValidator::nameError($name)
            ?? RuntimeCapabilityValidator::staticToolNameError($name);
        if ($nameError !== null) {
            return $nameError;
        }

        $description = trim($schema->description);
        if ($description === '') {
            return 'Runtime tool description cannot be empty.';
        }

        $instructions = trim($schema->instructions);
        if ($instructions === '') {
            return 'Runtime tool instructions cannot be empty.';
        }

        $schemaError = RuntimeCapabilityValidator::parametersSchemaError($schema->parametersSchema);
        if ($schemaError !== null) {
            return $schemaError;
        }

        $parametersSchema = RuntimeCapabilityValidator::encodeParametersSchema($schema->parametersSchema);

        /** @var \Bot\Entity\RuntimeTool\RuntimeToolRepository $repo */
        $repo = $this->orm->getRepository(RuntimeTool::class);
        $tool = $repo->findByName($chatId, $name);
        $created = $tool === null;

        if ($tool === null) {
            $tool = new RuntimeTool(
                chatId: $chatId,
                name: $name,
                description: $description,
                parametersSchema: $parametersSchema,
                instructions: $instructions,
                enabled: $schema->enabled,
            );
        } else {
            $tool->description = $description;
            $tool->parametersSchema = $parametersSchema;
            $tool->instructions = $instructions;
            $tool->enabled = $schema->enabled;
            $tool->touch();
        }

        $repo->save($tool);

        return sprintf(
            'Runtime tool "%s" %s and is %s.',
            $name,
            $created ? 'created' : 'updated',
            $tool->enabled ? 'enabled' : 'disabled',
        );
    }
}
