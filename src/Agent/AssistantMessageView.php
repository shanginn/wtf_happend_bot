<?php

declare(strict_types=1);

namespace Bot\Agent;

use Shanginn\Openai\ChatCompletion\CompletionRequest\Role;
use Shanginn\Openai\ChatCompletion\Message\Assistant\ToolCallInterface;

class AssistantMessageView
{
    public function __construct(
        public ?string $content = null,
        public ?string $name = null,
        public ?string $refusal = null,
        #[Serde\SequenceField(arrayType: ToolCallInterface::class)]
        public ?array $toolCalls = null,
    ) {
        $this->role = Role::ASSISTANT;

        if ($content === null && $toolCalls === null) {
            throw new InvalidArgumentException('Either content or toolCalls must be provided.');
        }
    }

}