<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Telegram;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'telegram_api_call',
    description: 'Call any Telegram Bot API method exposed by the installed Phenogram ApiInterface. Use telegram_api_schema first if you are unsure about a method signature. Parameters may be camelCase or snake_case; chat_id is injected for the current chat when the method accepts it and you omit it.',
)]
class TelegramApiCall extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'method',
            description: 'Telegram Bot API method name, e.g. sendMessage, sendPhoto, sendPoll, editMessageText, pinChatMessage, banChatMember.'
        )]
        public readonly string $method,

        #[Field(
            title: 'parameters',
            description: 'JSON object with method parameters. Use either Telegram snake_case keys or Phenogram camelCase keys. Omit chat_id/chatId to target the current chat when the method has a chatId parameter.'
        )]
        public readonly array $parameters = [],
    ) {}
}
