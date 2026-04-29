<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Runtime;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'upsert_runtime_skill',
    description: 'Create or update a chat-scoped runtime skill in the database. Skills are durable prompt instructions loaded into future response-agent prompts for this chat.',
)]
class UpsertRuntimeSkill extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'name',
            description: 'Stable skill name. It will be normalized to lowercase letters, digits, underscores, and hyphens.'
        )]
        public readonly string $name,

        #[Field(
            title: 'description',
            description: 'Short description of when the skill applies.'
        )]
        public readonly string $description,

        #[Field(
            title: 'body',
            description: 'Full skill instructions in Markdown or plain text.'
        )]
        public readonly string $body,

        #[Field(
            title: 'enabled',
            description: 'Whether the skill should be active immediately.'
        )]
        public readonly bool $enabled = true,
    ) {}
}
