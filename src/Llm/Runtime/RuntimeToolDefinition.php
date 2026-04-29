<?php

declare(strict_types=1);

namespace Bot\Llm\Runtime;

use Bot\Entity\RuntimeTool;

final readonly class RuntimeToolDefinition
{
    /**
     * @param array<string, mixed> $parametersSchema
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parametersSchema,
        public string $instructions,
    ) {}

    public static function fromEntity(RuntimeTool $tool): self
    {
        return new self(
            name: $tool->name,
            description: $tool->description,
            parametersSchema: RuntimeCapabilityValidator::decodeParametersSchema($tool->parametersSchema),
            instructions: $tool->instructions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toOpenaiTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => RuntimeCapabilityValidator::normalizeParametersSchema($this->parametersSchema),
            ],
        ];
    }
}
