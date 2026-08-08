<?php

declare(strict_types=1);

namespace Bot\Llm\Runtime;

use Bot\AgenticWorkflow\BotToolCatalog;
use Bot\Entity\RuntimeSkill;
use Bot\Entity\RuntimeTool;
use JsonException;
use PiPHP\AI\Tool\Tool;
use PiPHP\AI\Tool\ToolValidator;
use stdClass;
use Throwable;
use UnexpectedValueException;

final class RuntimeCapabilityValidator
{
    public const int MAX_NAME_BYTES              = 64;
    public const int MAX_DESCRIPTION_BYTES       = 500;
    public const int MAX_SKILL_BODY_BYTES        = 8000;
    public const int MAX_TOOL_INSTRUCTIONS_BYTES = 8000;
    public const int MAX_PARAMETERS_SCHEMA_BYTES = 8000;
    public const int MAX_CAPABILITIES_PER_KIND   = 20;
    public const int MAX_ENABLED_BYTES_PER_CHAT  = 50000;
    public const int MAX_LIST_LIMIT              = 20;

    private const string NAME_PATTERN = '/^[a-zA-Z0-9_-]+$/';
    private const int JSON_FLAGS      = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

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

        if (
            strlen($name) > self::MAX_NAME_BYTES
            || preg_match(self::NAME_PATTERN, $name) !== 1
        ) {
            return 'Name must contain only letters, digits, underscores, or hyphens, and be at most 64 characters.';
        }

        return null;
    }

    public static function byteLimitError(string $value, int $limit, string $label): ?string
    {
        $bytes = strlen($value);
        if ($bytes <= $limit) {
            return null;
        }

        return sprintf(
            '%s must be at most %d bytes; received %d bytes.',
            $label,
            $limit,
            $bytes,
        );
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
        return BotToolCatalog::toolNames();
    }

    /**
     * @return array<string>
     */
    public static function staticSkillNames(): array
    {
        return ['conversation', 'memory', 'search', 'telegram', 'runtime_capabilities'];
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

        try {
            $normalized = self::normalizeParametersSchema($schema);
            if ($normalized['properties'] instanceof stdClass) {
                $normalized['properties'] = [];
            }

            (new ToolValidator())->assertSupportedSchema(new Tool(
                name: 'runtime_schema',
                description: 'Runtime tool schema validation.',
                parameters: $normalized,
            ));
        } catch (Throwable $error) {
            return 'Unsupported parameters_schema: ' . $error->getMessage();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public static function normalizeParametersSchema(array $schema): array
    {
        $schema['type'] = 'object';

        if (!array_key_exists('properties', $schema) || $schema['properties'] === []) {
            $schema['properties'] = new stdClass();
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
     * @param string $schema
     *
     * @return array<string, mixed>
     */
    public static function decodeParametersSchema(string $schema): array
    {
        if (strlen($schema) > self::MAX_PARAMETERS_SCHEMA_BYTES) {
            throw new UnexpectedValueException(sprintf(
                'Stored parameters schema exceeds the %d-byte limit.',
                self::MAX_PARAMETERS_SCHEMA_BYTES,
            ));
        }

        try {
            $object  = json_decode($schema, flags: \JSON_THROW_ON_ERROR);
            $decoded = json_decode($schema, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException(
                'Stored parameters schema is not valid JSON.',
                previous: $error,
            );
        }

        if (!$object instanceof stdClass || !is_array($decoded)) {
            throw new UnexpectedValueException('Stored parameters schema must be a JSON object.');
        }

        $schemaError = self::parametersSchemaError($decoded);
        if ($schemaError !== null) {
            throw new UnexpectedValueException($schemaError);
        }

        return self::normalizeParametersSchema($decoded);
    }

    public static function runtimeSkillBytes(
        string $name,
        string $description,
        string $body,
    ): int {
        return strlen($name) + strlen($description) + strlen($body);
    }

    public static function storedRuntimeSkillBytes(RuntimeSkill $skill): int
    {
        return self::runtimeSkillBytes($skill->name, $skill->description, $skill->body);
    }

    public static function storedRuntimeSkillError(RuntimeSkill $skill): ?string
    {
        $nameError = self::nameError($skill->name);
        if ($nameError !== null) {
            return $nameError;
        }

        if (trim($skill->description) === '') {
            return 'Skill description cannot be empty.';
        }
        $descriptionError = self::byteLimitError(
            $skill->description,
            self::MAX_DESCRIPTION_BYTES,
            'Skill description',
        );
        if ($descriptionError !== null) {
            return $descriptionError;
        }

        if (trim($skill->body) === '') {
            return 'Skill body cannot be empty.';
        }

        return self::byteLimitError(
            $skill->body,
            self::MAX_SKILL_BODY_BYTES,
            'Skill body',
        );
    }

    public static function runtimeToolBytes(
        string $name,
        string $description,
        string $parametersSchema,
        string $instructions,
    ): int {
        return strlen($name)
            + strlen($description)
            + strlen($parametersSchema)
            + strlen($instructions);
    }

    public static function storedRuntimeToolBytes(RuntimeTool $tool): int
    {
        return self::runtimeToolBytes(
            $tool->name,
            $tool->description,
            $tool->parametersSchema,
            $tool->instructions,
        );
    }

    public static function storedRuntimeToolError(RuntimeTool $tool): ?string
    {
        $nameError = self::nameError($tool->name) ?? self::staticToolNameError($tool->name);
        if ($nameError !== null) {
            return $nameError;
        }

        if (trim($tool->description) === '') {
            return 'Runtime tool description cannot be empty.';
        }
        $descriptionError = self::byteLimitError(
            $tool->description,
            self::MAX_DESCRIPTION_BYTES,
            'Runtime tool description',
        );
        if ($descriptionError !== null) {
            return $descriptionError;
        }

        if (trim($tool->instructions) === '') {
            return 'Runtime tool instructions cannot be empty.';
        }
        $instructionsError = self::byteLimitError(
            $tool->instructions,
            self::MAX_TOOL_INSTRUCTIONS_BYTES,
            'Runtime tool instructions',
        );
        if ($instructionsError !== null) {
            return $instructionsError;
        }

        try {
            self::decodeParametersSchema($tool->parametersSchema);
        } catch (UnexpectedValueException $error) {
            return $error->getMessage();
        }

        return null;
    }

    /**
     * @param iterable<RuntimeSkill> $skills
     * @param iterable<RuntimeTool>  $tools
     */
    public static function enabledBytes(iterable $skills, iterable $tools): int
    {
        $bytes = 0;

        foreach ($skills as $skill) {
            if ($skill->enabled) {
                $bytes += self::storedRuntimeSkillBytes($skill);
            }
        }

        foreach ($tools as $tool) {
            if ($tool->enabled) {
                $bytes += self::storedRuntimeToolBytes($tool);
            }
        }

        return $bytes;
    }
}
