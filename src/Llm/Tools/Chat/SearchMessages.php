<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Chat;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'search_messages',
    description: 'Search all persisted chat history for a non-empty query, including user messages and bot replies, and return compact latest matches. Leave the query empty only to load recent chat history. Use username "bot" or "assistant" to filter bot replies.',
)]
class SearchMessages extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'query',
            description: 'Optional text to search for in chat history. Leave empty to load recent messages instead of searching.'
        )]
        public readonly string $query = '',

        #[Field(
            title: 'username',
            description: 'Optional: filter results to messages from a specific participant reference or username (with or without @). Leave empty to search all users.'
        )]
        public readonly ?string $username = null,

        #[Field(
            title: 'limit',
            description: 'Maximum number of results to return (default 10, max 30)'
        )]
        public readonly int $limit = 10,
    ) {}
}
