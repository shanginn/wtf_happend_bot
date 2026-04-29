<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Runtime;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'set_runtime_capability_status',
    description: 'Enable or disable a chat-scoped runtime skill or generated runtime tool stored in the database.',
)]
class SetRuntimeCapabilityStatus extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'kind',
            description: 'Capability kind: "skill" or "tool".'
        )]
        public readonly string $kind,

        #[Field(
            title: 'name',
            description: 'Capability name. It will be normalized the same way as upsert_runtime_skill/upsert_runtime_tool.'
        )]
        public readonly string $name,

        #[Field(
            title: 'enabled',
            description: 'Whether the capability should be enabled.'
        )]
        public readonly bool $enabled,
    ) {}
}
