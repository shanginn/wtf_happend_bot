<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Memory;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'recall_memory',
    description: 'Recall saved memories about one participant or search across the chat. Search matches the computed memory, quote, and saved surrounding context.',
)]
class RecallMemory extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'user_identifier',
            description: 'Optional participant reference to look up, such as "@alice", "alice", or "user_123456". Leave empty to search across the whole chat.'
        )]
        public readonly ?string $userIdentifier = null,

        #[Field(
            title: 'query',
            description: 'Optional free-text search over computed memory, supporting quote, and saved context.'
        )]
        public readonly ?string $query = null,

        #[Field(
            title: 'limit',
            description: 'Maximum number of memories to return. Default 10, maximum 20.'
        )]
        public readonly int $limit = 10,
    ) {}
}
