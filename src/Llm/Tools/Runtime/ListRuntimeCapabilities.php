<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Runtime;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'list_runtime_capabilities',
    description: 'List chat-scoped runtime skills and generated runtime tools stored in the database. Use before updating a capability when the exact name is unclear.',
)]
class ListRuntimeCapabilities extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'kind',
            description: 'Filter to "all", "skill", or "tool".'
        )]
        public readonly string $kind = 'all',

        #[Field(
            title: 'include_disabled',
            description: 'Whether to include disabled runtime skills and tools.'
        )]
        public readonly bool $includeDisabled = false,
    ) {}
}
