<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Memory;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'save_memory',
    description: 'Save a fact or note about a group chat user for future reference. Use this to remember important details about users such as their real name, interests, expertise, preferences, or notable things they said.',
)]
class SaveMemory extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'user_identifier',
            description: 'The Telegram username (with @) or user ID of the person this memory is about'
        )]
        public readonly string $userIdentifier,

        #[Field(
            title: 'category',
            description: 'Category of the memory: "personal" (name, bio, interests), "expertise" (skills, knowledge areas), "preference" (likes, dislikes, communication style), "note" (general observations)'
        )]
        public readonly string $category,

        #[Field(
            title: 'content',
            description: 'The fact or note to remember about this user. Be concise but specific.'
        )]
        public readonly string $content,
    ) {}
}
