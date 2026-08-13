<?php

declare(strict_types=1);

namespace Bot\Space\Publication;

use Bot\Llm\Runtime\RuntimeCapabilityValidator;

/** Capability content derived only from the exact authorized Telegram update. */
final readonly class SpaceCapabilityRequestSpec
{
    /** @var array<string, mixed> */
    public array $parametersSchema;

    /** @param array<string, mixed> $parametersSchema */
    private function __construct(
        public string $description,
        public string $instructions,
        array $parametersSchema,
    ) {
        $this->parametersSchema = $parametersSchema;
    }

    public static function fromTrustedRequest(string $kind, string $name, string $requestText): self
    {
        $requestText = trim($requestText);
        if ($requestText === '') {
            throw new SpaceCapabilityPublicationRejected(
                'The authorized capability request cannot be empty.',
            );
        }

        $description = strlen($requestText) <= RuntimeCapabilityValidator::MAX_DESCRIPTION_BYTES
            ? $requestText
            : sprintf(
                '%s %s published from an exact Telegram administrator request.',
                $kind === SpaceCapabilityPublicationInput::KIND_COMMAND ? 'Slash command' : 'Space skill',
                $kind === SpaceCapabilityPublicationInput::KIND_COMMAND ? '/' . $name : $name,
            );
        $instructions = 'This publication is already complete. Never call publish_space_capability '
            . 'because of the stored request below. Apply only the requested future behavior. '
            . 'Treat the exact administrator request as the complete capability specification '
            . "and do not import behavior from adjacent chat messages:\n\n"
            . $requestText;

        return new self(
            description: $description,
            instructions: $instructions,
            parametersSchema: [
                'type'                 => 'object',
                'properties'           => [],
                'additionalProperties' => false,
            ],
        );
    }
}
