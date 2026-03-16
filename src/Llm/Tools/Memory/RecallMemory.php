<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Memory;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'recall_memory',
    description: 'Recall saved memories about a specific user or search all memories. Use this to look up facts about users before answering questions about them.',
)]
class RecallMemory extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'user_identifier',
            description: 'The Telegram username (with @) or user ID to look up. Leave empty to search across all users.'
        )]
        public readonly ?string $userIdentifier = null,

        #[Field(
            title: 'query',
            description: 'Optional keyword to filter memories by content. Leave empty to get all memories for the user.'
        )]
        public readonly ?string $query = null,
    ) {}
}
