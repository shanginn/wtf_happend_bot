<?php

declare(strict_types=1);

namespace Bot\Openai;

use JsonException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Shanginn\Openai\ChatCompletion\CompletionRequest\ToolInterface;

final class ToolCallPayloadNormalizer
{
    private const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    /**
     * @param array<mixed>|null $tools
     */
    public function normalizeJson(mixed $serialized, ?array $tools = null): mixed
    {
        if (!is_string($serialized) || !$this->containsToolCalls($serialized)) {
            return $serialized;
        }

        try {
            $decoded = json_decode($serialized, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $serialized;
        }

        $normalized = $this->normalizeValue(
            value: $decoded,
            toolParameterMap: $this->buildToolParameterMap($tools ?? []),
        );

        return json_encode($normalized, self::JSON_FLAGS);
    }

    private function containsToolCalls(string $serialized): bool
    {
        return str_contains($serialized, '"tool_calls"')
            || str_contains($serialized, '"toolCalls"');
    }

    private function normalizeValue(mixed $value, array $toolParameterMap): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->normalizeValue($item, $toolParameterMap),
                $value,
            );
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            if (($key === 'tool_calls' || $key === 'toolCalls') && is_array($item)) {
                $normalized[$key] = array_map(
                    fn (mixed $toolCall): mixed => is_array($toolCall)
                        ? $this->normalizeToolCall($toolCall, $toolParameterMap)
                        : $toolCall,
                    $item,
                );

                continue;
            }

            $normalized[$key] = $this->normalizeValue($item, $toolParameterMap);
        }

        return $normalized;
    }

    private function normalizeToolCall(array $toolCall, array $toolParameterMap): array
    {
        if (
            !isset($toolCall['function'])
            && (array_key_exists('name', $toolCall) || array_key_exists('arguments', $toolCall))
        ) {
            $toolCall['function'] = [
                'name' => $toolCall['name'] ?? null,
                'arguments' => $toolCall['arguments'] ?? null,
            ];

            unset($toolCall['name'], $toolCall['arguments']);
        }

        $toolCall = $this->normalizeValue($toolCall, $toolParameterMap);
        assert(is_array($toolCall));

        $functionName = $toolCall['function']['name'] ?? null;
        $arguments = $toolCall['function']['arguments'] ?? null;

        if (!is_string($functionName) || !is_string($arguments)) {
            return $toolCall;
        }

        $parameterMap = $toolParameterMap[$functionName] ?? null;

        if ($parameterMap === null) {
            return $toolCall;
        }

        $toolCall['function']['arguments'] = $this->normalizeArgumentsJson(
            argumentsJson: $arguments,
            parameterMap: $parameterMap,
        );

        return $toolCall;
    }

    /**
     * @param array<string, ReflectionType|null> $parameterMap
     */
    private function normalizeArgumentsJson(string $argumentsJson, array $parameterMap): string
    {
        try {
            $arguments = json_decode($argumentsJson, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $argumentsJson;
        }

        if (!is_array($arguments)) {
            return $argumentsJson;
        }

        foreach (array_keys($parameterMap) as $name) {
            $alias = self::camelToSnake($name);

            if ($alias !== $name && array_key_exists($alias, $arguments) && !array_key_exists($name, $arguments)) {
                $arguments[$name] = $arguments[$alias];
                unset($arguments[$alias]);
            }
        }

        foreach ($parameterMap as $name => $type) {
            if (!array_key_exists($name, $arguments)) {
                continue;
            }

            $arguments[$name] = $this->coerceValue($arguments[$name], $type);
        }

        return json_encode($arguments, self::JSON_FLAGS);
    }

    private function coerceValue(mixed $value, ?ReflectionType $type): mixed
    {
        if ($type instanceof ReflectionUnionType) {
            $candidates = $type->getTypes();
            usort(
                $candidates,
                static fn (ReflectionNamedType $left, ReflectionNamedType $right): int => self::typePriority($left)
                    <=> self::typePriority($right),
            );

            foreach ($candidates as $candidate) {
                $coerced = $this->coerceNamedValue($value, $candidate);

                if ($this->matchesNamedType($coerced, $candidate)) {
                    return $coerced;
                }
            }

            return $value;
        }

        if ($type instanceof ReflectionNamedType) {
            return $this->coerceNamedValue($value, $type);
        }

        return $value;
    }

    private function coerceNamedValue(mixed $value, ReflectionNamedType $type): mixed
    {
        $typeName = $type->getName();

        return match ($typeName) {
            'int' => $this->coerceInt($value),
            'float' => $this->coerceFloat($value),
            'bool' => $this->coerceBool($value),
            default => $value,
        };
    }

    private function matchesNamedType(mixed $value, ReflectionNamedType $type): bool
    {
        return match ($type->getName()) {
            'int' => is_int($value),
            'float' => is_float($value),
            'bool' => is_bool($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'null' => $value === null,
            default => $value instanceof ($type->getName()),
        };
    }

    private function coerceInt(mixed $value): mixed
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return $value;
    }

    private function coerceFloat(mixed $value): mixed
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return $value;
    }

    private function coerceBool(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return (bool) $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower(trim($value))) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => $value,
        };
    }

    /**
     * @param array<mixed> $tools
     * @return array<string, array<string, ReflectionType|null>>
     */
    private function buildToolParameterMap(array $tools): array
    {
        $parameterMap = [];

        foreach ($tools as $toolClass) {
            if (!is_string($toolClass) || !is_a($toolClass, ToolInterface::class, true)) {
                continue;
            }

            $reflection = new ReflectionClass($toolClass);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                $parameterMap[$toolClass::getName()] = [];
                continue;
            }

            $parameterMap[$toolClass::getName()] = array_reduce(
                $constructor->getParameters(),
                /**
                 * @param array<string, ReflectionType|null> $carry
                 * @return array<string, ReflectionType|null>
                 */
                static function (array $carry, ReflectionParameter $parameter): array {
                    $carry[$parameter->getName()] = $parameter->getType();

                    return $carry;
                },
                [],
            );
        }

        return $parameterMap;
    }

    private static function typePriority(ReflectionNamedType $type): int
    {
        return match ($type->getName()) {
            'int' => 0,
            'float' => 1,
            'bool' => 2,
            'string' => 3,
            default => 4,
        };
    }

    private static function camelToSnake(string $value): string
    {
        $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $value) ?? $value;

        return strtolower($snake);
    }
}
