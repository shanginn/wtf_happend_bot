<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Chat;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'get_current_time',
    description: 'Get the current date and time. Useful for answering questions about what time it is, scheduling, or time-zone conversions.',
)]
class GetCurrentTime extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'timezone',
            description: 'IANA timezone name (e.g. "Europe/Moscow", "America/New_York", "Asia/Tokyo"). Defaults to UTC.'
        )]
        public readonly string $timezone = 'UTC',
    ) {}
}
