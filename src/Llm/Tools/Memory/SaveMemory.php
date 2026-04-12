<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Memory;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'save_memory',
    description: 'Save a durable computed memory about a chat participant. Always include the computed memory, an exact supporting quote, and the brief surrounding context where the fact came from.',
)]
class SaveMemory extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'user_identifier',
            description: 'Participant reference for this memory. Prefer the exact participant reference from the message name field when available, such as "@alice", "alice", or "user_123456".'
        )]
        public readonly string $userIdentifier,

        #[Field(
            title: 'memory',
            description: 'The computed durable memory to store in one sentence.'
        )]
        public readonly string $memory,

        #[Field(
            title: 'quote',
            description: 'A short direct quote from the chat that supports the memory.'
        )]
        public readonly string $quote,

        #[Field(
            title: 'context',
            description: 'Brief surrounding context that explains the quote and why the memory matters.'
        )]
        public readonly string $context,
    ) {}
}
