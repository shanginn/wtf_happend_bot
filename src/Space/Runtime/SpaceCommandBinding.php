<?php

declare(strict_types=1);

namespace Bot\Space\Runtime;

use InvalidArgumentException;
use PiPHP\AI\Tool\Tool;
use PiPHP\AI\Tool\ToolValidator;
use Throwable;

/** One complete, release-pinned Telegram command specification. */
final readonly class SpaceCommandBinding
{
    private const int MAX_NAME_BYTES              = 32;
    private const int MAX_DESCRIPTION_BYTES       = 500;
    private const int MAX_INSTRUCTIONS_BYTES      = 8_000;
    private const int MAX_PARAMETERS_SCHEMA_BYTES = 8_000;

    /** @param array<string, mixed> $parametersSchema */
    public function __construct(
        public string $name,
        public string $description,
        public string $instructions,
        public array $parametersSchema,
    ) {
        if ($name !== self::normalizeName($name)
            || preg_match('/\A[a-z0-9_]{1,32}\z/D', $name) !== 1
        ) {
            throw new InvalidArgumentException(
                'Space command names must be canonical lowercase Telegram command names.',
            );
        }
        if (trim($description) === '' || strlen($description) > self::MAX_DESCRIPTION_BYTES) {
            throw new InvalidArgumentException(
                'Space command descriptions must be non-empty and at most 500 bytes.',
            );
        }
        if (trim($instructions) === '' || strlen($instructions) > self::MAX_INSTRUCTIONS_BYTES) {
            throw new InvalidArgumentException(
                'Space command instructions must be non-empty and at most 8000 bytes.',
            );
        }

        $schemaBytes = strlen(json_encode(
            $parametersSchema,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        ));
        if ($schemaBytes > self::MAX_PARAMETERS_SCHEMA_BYTES) {
            throw new InvalidArgumentException(
                'Space command parameters schema must be at most 8000 bytes.',
            );
        }
        if (($parametersSchema['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException(
                'Space command parameters schema must have type "object".',
            );
        }

        try {
            (new ToolValidator())->assertSupportedSchema(new Tool(
                name: $name,
                description: $description,
                parameters: $parametersSchema,
            ));
        } catch (Throwable $error) {
            throw new InvalidArgumentException(
                'Space command parameters schema is unsupported: ' . $error->getMessage(),
                previous: $error,
            );
        }
    }

    public static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        if (str_starts_with($name, '/')) {
            $name = substr($name, 1);
        }
        if (($separator = strpos($name, '@')) !== false) {
            $name = substr($name, 0, $separator);
        }

        return $name;
    }
}
