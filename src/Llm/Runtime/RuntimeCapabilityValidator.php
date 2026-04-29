<?php

declare(strict_types=1);

namespace Bot\Llm\Runtime;

use Bot\AgenticWorkflow\AgenticToolset;

final class RuntimeCapabilityValidator
{
    private const string NAME_PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/';
    private const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    public static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_-]+/', '_', $name) ?? $name;

        return trim($name, '_');
    }

    public static function nameError(string $name): ?string
    {
        if ($name === '') {
            return 'Name cannot be empty.';
        }

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return 'Name must contain only letters, digits, underscores, or hyphens, and be at most 64 characters.';
        }

        return null;
    }

    public static function staticToolNameError(string $name): ?string
    {
        if (!in_array($name, self::staticToolNames(), true)) {
            return null;
        }

        return sprintf(
            'Runtime tool "%s" conflicts with a built-in PHP tool. Choose a different name.',
            $name,
        );
    }

    /**
     * @return array<string>
     */
    public static function staticToolNames(): array
    {
        return array_map(
            static fn (string $toolClass): string => $toolClass::getName(),
            AgenticToolset::TOOLS,
        );
    }

    /**
     * @return array<string>
     */
    public static function staticSkillNames(): array
    {
        return array_map(
            static fn (string $skillClass): string => $skillClass::name(),
            AgenticToolset::SKILLS,
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    public static function parametersSchemaError(array $schema): ?string
    {
        if (($schema['type'] ?? 'object') !== 'object') {
            return 'parameters_schema must be a JSON schema object with type "object".';
        }

        if (isset($schema['properties']) && !is_array($schema['properties'])) {
            return 'parameters_schema.properties must be an object.';
        }

        if (isset($schema['required']) && !is_array($schema['required'])) {
            return 'parameters_schema.required must be an array of property names.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function normalizeParametersSchema(array $schema): array
    {
        $schema['type'] = 'object';

        if (!array_key_exists('properties', $schema) || $schema['properties'] === []) {
            $schema['properties'] = new \stdClass();
        }

        if (!array_key_exists('additionalProperties', $schema)) {
            $schema['additionalProperties'] = false;
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     */
    public static function encodeParametersSchema(array $schema): string
    {
        return json_encode(self::normalizeParametersSchema($schema), self::JSON_FLAGS);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeParametersSchema(string $schema): array
    {
        try {
            $decoded = json_decode($schema, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::normalizeParametersSchema([]);
        }

        if (!is_array($decoded)) {
            return self::normalizeParametersSchema([]);
        }

        return self::normalizeParametersSchema($decoded);
    }
}
