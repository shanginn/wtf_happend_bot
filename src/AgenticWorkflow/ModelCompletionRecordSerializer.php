<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use PiPHP\Temporal\DTO\ModelActivityResult;
use UnexpectedValueException;

final class ModelCompletionRecordSerializer
{
    private function __construct() {}

    public static function encode(ModelActivityResult $result): string
    {
        return json_encode([
            'assistantMessage' => $result->assistantMessage,
            'toolCalls'        => $result->toolCalls,
            'stopReason'       => $result->stopReason,
            'usage'            => $result->usage,
            'errorMessage'     => $result->errorMessage,
        ], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function decode(string $json): ModelActivityResult
    {
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new UnexpectedValueException('Stored model completion result must be a JSON object.');
        }

        return new ModelActivityResult(
            assistantMessage: self::arrayValue($data, 'assistantMessage'),
            toolCalls: self::arrayValue($data, 'toolCalls'),
            stopReason: self::stringValue($data, 'stopReason'),
            usage: self::arrayValue($data, 'usage'),
            errorMessage: isset($data['errorMessage']) && is_string($data['errorMessage'])
                ? $data['errorMessage']
                : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $key
     *
     * @return array<mixed>
     */
    private static function arrayValue(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new UnexpectedValueException("Stored model completion result field {$key} must be an array.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $key
     */
    private static function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new UnexpectedValueException(
                "Stored model completion result field {$key} must be a non-empty string.",
            );
        }

        return $value;
    }
}
