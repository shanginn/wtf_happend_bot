<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Chat;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'search_messages',
    description: 'Search through persisted chat history. Use a query to find specific older messages, or leave the query empty to load recent chat history.',
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
