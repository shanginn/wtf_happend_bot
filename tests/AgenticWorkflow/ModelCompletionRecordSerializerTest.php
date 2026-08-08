<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\ModelCompletionRecordSerializer;
use PiPHP\Temporal\DTO\ModelActivityResult;
use Tests\TestCase;
use UnexpectedValueException;

final class ModelCompletionRecordSerializerTest extends TestCase
{
    public function testRoundTripsPortableModelResult(): void
    {
        $result = new ModelActivityResult(
            assistantMessage: [
                'role' => 'assistant',
                'content' => [[
                    'type' => 'toolCall',
                    'id' => 'call-1',
                    'name' => 'get_current_time',
                    'arguments' => ['timezone' => 'Asia/Yekaterinburg'],
                ]],
                'provider' => 'deepseek',
                'model' => 'deepseek-v4-flash',
            ],
            toolCalls: [[
                'id' => 'call-1',
                'name' => 'get_current_time',
                'arguments' => ['timezone' => 'Asia/Yekaterinburg'],
            ]],
            stopReason: 'tool_use',
            usage: ['input' => 123, 'costTotal' => 0.0025],
        );

        $decoded = ModelCompletionRecordSerializer::decode(
            ModelCompletionRecordSerializer::encode($result),
        );

        self::assertSame($result->assistantMessage, $decoded->assistantMessage);
        self::assertSame($result->toolCalls, $decoded->toolCalls);
        self::assertSame($result->stopReason, $decoded->stopReason);
        self::assertSame($result->usage, $decoded->usage);
        self::assertNull($decoded->errorMessage);
    }

    public function testRejectsIncompleteStoredResult(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('assistantMessage');

        ModelCompletionRecordSerializer::decode('{"stopReason":"stop"}');
    }
}
