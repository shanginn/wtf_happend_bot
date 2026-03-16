<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Chat;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'search_messages',
    description: 'Search through the chat message history. Use this to find old messages, look up what someone said, or find when a topic was discussed.',
)]
class SearchMessages extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'query',
            description: 'Text to search for in message history. Searches message content.'
        )]
        public readonly string $query,

        #[Field(
            title: 'username',
            description: 'Optional: filter results to messages from a specific username (with @). Leave empty to search all users.'
        )]
        public readonly ?string $username = null,

        #[Field(
            title: 'limit',
            description: 'Maximum number of results to return (default 10, max 30)'
        )]
        public readonly int $limit = 10,
    ) {}
}
