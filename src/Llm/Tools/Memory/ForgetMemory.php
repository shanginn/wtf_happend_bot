<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Memory;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'forget_memory',
    description: 'Delete saved participant memories. Use memory_id from recall_memory for a single memory, or use participant/query selectors. Set forget_all_for_participant only when the user explicitly asks to forget all memories for that participant.',
)]
class ForgetMemory extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'memory_id',
            description: 'Optional memory id from recall_memory. Prefer this when deleting one specific memory.'
        )]
        public readonly ?int $memoryId = null,

        #[Field(
            title: 'user_identifier',
            description: 'Optional participant reference to narrow deletion, such as "@alice", "alice", or "user_123456". Required when forget_all_for_participant is true.'
        )]
        public readonly ?string $userIdentifier = null,

        #[Field(
            title: 'query',
            description: 'Optional narrow search query over computed memory, quote, and context.'
        )]
        public readonly ?string $query = null,

        #[Field(
            title: 'forget_all_for_participant',
            description: 'Set true only when the user explicitly asks to forget every saved memory for the specified participant.'
        )]
        public readonly bool $forgetAllForParticipant = false,
    ) {}
}
