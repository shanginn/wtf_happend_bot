<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Decision;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'respond_decision',
    description: 'Call this to indicate whether the bot should respond to the messages. If not called, the bot will NOT respond.',
)]
class RespondDecision extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'should_respond',
            description: 'True if the bot should respond to these messages'
        )]
        public readonly bool $shouldRespond,
        
        #[Field(
            title: 'reason',
            description: 'Brief explanation of why the bot should or should not respond'
        )]
        public readonly string $reason,
    ) {}
}
