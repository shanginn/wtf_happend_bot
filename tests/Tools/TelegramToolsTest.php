<?php

declare(strict_types=1);

namespace Tests\Tools;

use Bot\Llm\Tools\Telegram\TelegramApiCall;
use Bot\Llm\Tools\Telegram\TelegramApiSchema;
use Tests\TestCase;

class TelegramToolsTest extends TestCase
{
    public function testTelegramApiCallToolName(): void
    {
        self::assertSame('telegram_api_call', TelegramApiCall::getName());
    }

    public function testTelegramApiCallConstruction(): void
    {
        $tool = new TelegramApiCall(
            method: 'sendMessage',
            parameters: ['text' => 'Hello'],
        );

        self::assertSame('sendMessage', $tool->method);
        self::assertSame(['text' => 'Hello'], $tool->parameters);
    }

    public function testTelegramApiSchemaToolName(): void
    {
        self::assertSame('telegram_api_schema', TelegramApiSchema::getName());
    }

    public function testTelegramApiSchemaDefaults(): void
    {
        $tool = new TelegramApiSchema();

        self::assertNull($tool->method);
        self::assertNull($tool->query);
        self::assertSame(20, $tool->limit);
    }
}
