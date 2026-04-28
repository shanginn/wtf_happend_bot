<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Telegram;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'telegram_api_schema',
    description: 'Inspect Telegram Bot API methods available through the installed Phenogram ApiInterface. Use this before telegram_api_call when you need parameter names, return types, or method discovery.',
)]
class TelegramApiSchema extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'method',
            description: 'Optional exact Telegram Bot API method name to inspect, e.g. sendMessage or createChatInviteLink.'
        )]
        public readonly ?string $method = null,

        #[Field(
            title: 'query',
            description: 'Optional search text to find methods by method name or PHPDoc description, e.g. poll, invite, photo, reaction.'
        )]
        public readonly ?string $query = null,

        #[Field(
            title: 'limit',
            description: 'Maximum number of matching methods to return when searching or listing methods. Default 20, max 80.'
        )]
        public readonly int $limit = 20,
    ) {}
}
