<?php

declare(strict_types=1);

namespace Bot\Space\Command;

use Bot\Space\Runtime\SpaceCommandBinding;
use InvalidArgumentException;
use PiPHP\Temporal\Serialization\HistoryPayloadGuard;

final readonly class SpaceCommandExecutionInput
{
    private const int MODEL_ENVELOPE_HEADROOM_BYTES = 32_000;

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $metadata
     * @param string                     $model
     * @param SpaceCommandBinding        $binding
     * @param string                     $argumentText
     * @param string                     $idempotencyKey
     */
    public function __construct(
        public string $model,
        public SpaceCommandBinding $binding,
        public string $argumentText,
        public array $messages,
        public array $metadata,
        public string $idempotencyKey,
    ) {
        if (trim($model) === '' || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException(
                'Space command model and idempotency key must be non-empty.',
            );
        }

        HistoryPayloadGuard::assertMessages($messages);
        HistoryPayloadGuard::assertJsonValue($metadata, 'spaceCommand.metadata');
        $payload = [
            'model'   => $model,
            'binding' => [
                'name'             => $binding->name,
                'description'      => $binding->description,
                'instructions'     => $binding->instructions,
                'parametersSchema' => $binding->parametersSchema,
            ],
            'argumentText'   => $argumentText,
            'messages'       => $messages,
            'metadata'       => $metadata,
            'idempotencyKey' => $idempotencyKey,
        ];
        HistoryPayloadGuard::assertJsonValue($payload, 'spaceCommand');
        if (
            HistoryPayloadGuard::encodedBytes($payload)
            > HistoryPayloadGuard::MAX_ENCODED_BYTES - self::MODEL_ENVELOPE_HEADROOM_BYTES
        ) {
            throw new InvalidArgumentException(
                'Space command input leaves insufficient room for the model request envelope.',
            );
        }
    }
}
