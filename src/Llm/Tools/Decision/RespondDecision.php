<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Decision;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'respond_decision',
    description: 'Call this to indicate whether the bot should respond to the messages and what to response with.',
)]
class RespondDecision extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'should_respond',
            description: <<<TEXT
                Rules for responding (should_respond = true):
                - Direct messages to the bot (private chat)
                - Bot is mentioned by username @wtf_happened_bot
                - Message is a reply to bot's message
                - User asks a question directed at the group that the bot could help with
                - User explicitly requests help, summary, or bot functionality
                - Command like /wtf, /help, /start
                - User refers to the бот, ботик etc
                
                Rules for NOT responding (should_respond = false):
                - Regular group chat conversation between users
                - Bot is not mentioned or involved
                - Just casual chat, jokes, or off-topic discussion
                - Users talking to each other without needing bot assistance
                TEXT

        )]
        public readonly bool $shouldRespond,
        
        #[Field(
            title: 'reason',
            description: 'Brief explanation of why the bot should or should not respond'
        )]
        public readonly string $reason,

        #[Field(
            title: 'response',
            description: 'Response to send back to the user if should_respond is true'
        )]
        public readonly string $response,
    ) {}
}
