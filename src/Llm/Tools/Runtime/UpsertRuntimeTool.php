<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Runtime;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'upsert_runtime_tool',
    description: 'Create or update a chat-scoped generated runtime tool in the database. Runtime tools are prompt-executed from stored instructions and JSON arguments; they do not run PHP code.',
)]
class UpsertRuntimeTool extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'name',
            description: 'Stable function name. It will be normalized to lowercase letters, digits, underscores, and hyphens.'
        )]
        public readonly string $name,

        #[Field(
            title: 'description',
            description: 'Short function description shown to the model in the tool schema.'
        )]
        public readonly string $description,

        #[Field(
            title: 'parameters_schema',
            description: 'JSON schema object for the function parameters. Root type must be object.'
        )]
        public readonly array $parametersSchema,

        #[Field(
            title: 'instructions',
            description: 'Execution instructions for the generic runtime-tool executor. It receives only the tool definition and JSON arguments.'
        )]
        public readonly string $instructions,

        #[Field(
            title: 'enabled',
            description: 'Whether the generated tool should be active immediately.'
        )]
        public readonly bool $enabled = true,
    ) {}
}
