<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Memory;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'update_memory',
    description: 'Replace one existing participant memory with a corrected durable memory. Use memory_id from recall_memory when available, otherwise provide a participant and a narrow current_memory or query selector.',
)]
class UpdateMemory extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'memory',
            description: 'The corrected durable memory to store in one sentence.'
        )]
        public readonly string $memory,

        #[Field(
            title: 'quote',
            description: 'A short direct quote from the chat that supports the corrected memory.'
        )]
        public readonly string $quote,

        #[Field(
            title: 'context',
            description: 'Brief surrounding context that explains the quote and why the corrected memory matters.'
        )]
        public readonly string $context,

        #[Field(
            title: 'memory_id',
            description: 'Optional memory id from recall_memory. Prefer this when available because it avoids ambiguous edits.'
        )]
        public readonly ?int $memoryId = null,

        #[Field(
            title: 'user_identifier',
            description: 'Optional participant reference to narrow the memory lookup, such as "@alice", "alice", or "user_123456".'
        )]
        public readonly ?string $userIdentifier = null,

        #[Field(
            title: 'current_memory',
            description: 'Optional exact current computed memory sentence to replace.'
        )]
        public readonly ?string $currentMemory = null,

        #[Field(
            title: 'query',
            description: 'Optional narrow search query over computed memory, quote, and context when memory_id or current_memory is unavailable.'
        )]
        public readonly ?string $query = null,
    ) {}
}
