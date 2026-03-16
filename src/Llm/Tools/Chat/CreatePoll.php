<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Chat;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'create_poll',
    description: 'Create a poll in the group chat. Use this when users want to vote on something, make a group decision, or gather opinions.',
)]
class CreatePoll extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'question',
            description: 'The poll question (1-300 characters)'
        )]
        public readonly string $question,

        #[Field(
            title: 'options',
            description: 'Array of 2-10 answer options for the poll'
        )]
        public readonly array $options,

        #[Field(
            title: 'is_anonymous',
            description: 'Whether the poll is anonymous (default true)'
        )]
        public readonly bool $isAnonymous = true,

        #[Field(
            title: 'allows_multiple_answers',
            description: 'Whether users can select multiple answers (default false)'
        )]
        public readonly bool $allowsMultipleAnswers = false,
    ) {}
}
