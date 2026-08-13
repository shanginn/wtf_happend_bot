<?php

declare(strict_types=1);

namespace Bot\Space\Publication;

use Bot\Llm\Runtime\RuntimeCapabilityValidator;
use Bot\Space\Memory\SpaceMemoryContentPolicy;
use Bot\Space\Persistence\SpaceId;
use Bot\Space\Runtime\SpaceCommandBinding;
use InvalidArgumentException;

/**
 * Host-authorized request to publish one Space capability in a new immutable
 * release. The authorization provenance is constructed by trusted host code;
 * model arguments must never be used to manufacture it.
 */
final readonly class SpaceCapabilityPublicationInput
{
    public const string KIND_SKILL          = 'skill';
    public const string KIND_COMMAND        = 'command';
    private const array EMPTY_OBJECT_SCHEMA = [
        'type'                 => 'object',
        'properties'           => [],
        'additionalProperties' => false,
    ];

    /** @var array<string, mixed> */
    public array $parametersSchema;

    /** @var array<string, mixed> */
    public array $authorizationProvenance;

    /**
     * @param array<string, mixed> $authorizationProvenance
     * @param array<string, mixed> $parametersSchema
     * @param string               $spaceId
     * @param string               $runtimeSnapshotId
     * @param string               $terminalScopeId
     * @param string               $invocationKey
     * @param string               $kind
     * @param string               $name
     * @param string               $description
     * @param string               $instructions
     * @param bool                 $enabled
     */
    public function __construct(
        public string $spaceId,
        public string $runtimeSnapshotId,
        public string $terminalScopeId,
        public string $invocationKey,
        public string $kind,
        public string $name,
        public string $description,
        public string $instructions,
        array $authorizationProvenance,
        array $parametersSchema = self::EMPTY_OBJECT_SCHEMA,
        public bool $enabled = true,
    ) {
        SpaceId::assert($spaceId);
        foreach ([
            'runtime snapshot ID' => $runtimeSnapshotId,
            'terminal scope ID'   => $terminalScopeId,
            'invocation key'      => $invocationKey,
            'description'         => $description,
            'instructions'        => $instructions,
        ] as $label => $value) {
            if (trim($value) === '') {
                throw new SpaceCapabilityPublicationRejected(
                    "Space capability publication {$label} cannot be empty.",
                );
            }
        }
        if (strlen($runtimeSnapshotId) > 160
            || strlen($terminalScopeId) > 255
            || strlen($invocationKey) > 255
        ) {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability publication identity exceeds its byte limit.',
            );
        }
        if (!in_array($kind, [self::KIND_SKILL, self::KIND_COMMAND], true)) {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability publication kind must be skill or command.',
            );
        }

        $normalizedName = $kind === self::KIND_COMMAND
            ? SpaceCommandBinding::normalizeName($name)
            : RuntimeCapabilityValidator::normalizeName($name);
        if ($normalizedName !== $name) {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability publication name must already be canonical.',
            );
        }

        if ($kind === self::KIND_COMMAND) {
            if (!$enabled) {
                throw new SpaceCapabilityPublicationRejected('A published Space command must be enabled.');
            }

            try {
                new SpaceCommandBinding($name, $description, $instructions, $parametersSchema);
            } catch (InvalidArgumentException $error) {
                throw new SpaceCapabilityPublicationRejected($error->getMessage(), previous: $error);
            }
        } else {
            if ($parametersSchema !== self::EMPTY_OBJECT_SCHEMA) {
                throw new SpaceCapabilityPublicationRejected(
                    'Space skills cannot declare command parameters.',
                );
            }
            $nameError = RuntimeCapabilityValidator::nameError($name);
            if ($nameError !== null) {
                throw new SpaceCapabilityPublicationRejected($nameError);
            }
            if (strlen($description) > RuntimeCapabilityValidator::MAX_DESCRIPTION_BYTES) {
                throw new SpaceCapabilityPublicationRejected(
                    'Space skill description exceeds its byte limit.',
                );
            }
            if (strlen($instructions) > RuntimeCapabilityValidator::MAX_SKILL_BODY_BYTES) {
                throw new SpaceCapabilityPublicationRejected(
                    'Space skill body exceeds its byte limit.',
                );
            }
        }

        if (SpaceMemoryContentPolicy::violations($description, $instructions) !== []
            || SpaceMemoryContentPolicy::nestedStringsHaveViolations($parametersSchema)
        ) {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability content cannot persist private or sensitive data.',
            );
        }

        self::assertAuthorizationProvenance($authorizationProvenance);
        if ($authorizationProvenance['spaceId'] !== $spaceId) {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability authorization provenance belongs to another Space.',
            );
        }
        $this->parametersSchema        = $parametersSchema;
        $this->authorizationProvenance = $authorizationProvenance;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'spaceId'           => $this->spaceId,
            'runtimeSnapshotId' => $this->runtimeSnapshotId,
            'terminalScopeId'   => $this->terminalScopeId,
            'invocationKey'     => $this->invocationKey,
            'kind'              => $this->kind,
            'name'              => $this->name,
            'description'       => trim($this->description),
            'instructions'      => trim($this->instructions),
            'parametersSchema'  => $this->parametersSchema,
            'enabled'           => $this->enabled,
        ];
    }

    /** @param array<string, mixed> $provenance */
    private static function assertAuthorizationProvenance(array $provenance): void
    {
        $expectedKeys = [
            'actorParticipantKey',
            'authorization',
            'batchId',
            'quoteSha256',
            'requestSha256',
            'requestUpdateId',
            'spaceId',
        ];
        $actualKeys = array_keys($provenance);
        sort($actualKeys, \SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability publication authorization provenance has unsupported or missing fields.',
            );
        }

        $provenanceSpaceId = $provenance['spaceId'] ?? null;
        $batchId           = $provenance['batchId'] ?? null;
        $requestUpdateId   = $provenance['requestUpdateId'] ?? null;
        $requestSha256     = $provenance['requestSha256'] ?? null;
        $quoteSha256       = $provenance['quoteSha256'] ?? null;
        $actor             = $provenance['actorParticipantKey'] ?? null;
        $authorization     = $provenance['authorization'] ?? null;
        if (!is_string($provenanceSpaceId)
            || $provenanceSpaceId === ''
            || !is_string($batchId)
            || $batchId === ''
            || strlen($batchId) > 255
            || !is_int($requestUpdateId)
            || $requestUpdateId < 0
            || !is_string($requestSha256)
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $requestSha256) !== 1
            || !is_string($quoteSha256)
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $quoteSha256) !== 1
            || !is_string($actor)
            || preg_match('/\Atelegram_user:[1-9]\d*\z/D', $actor) !== 1
            || !is_string($authorization)
            || !in_array($authorization, ['private-owner', 'telegram-admin'], true)
        ) {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability publication requires exact trusted Telegram authorization provenance.',
            );
        }
    }
}
