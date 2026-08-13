<?php

declare(strict_types=1);

namespace Bot\Space\Workflow;

use Bot\Space\Runtime\SpaceRuntimeSnapshot;
use Bot\Telegram\Factory;
use Bot\Telegram\Update;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\SerializerInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Temporal\Api\Common\V1\Payload;
use Temporal\DataConverter\EncodingKeys;
use Temporal\DataConverter\PayloadConverterInterface;
use Temporal\DataConverter\Type;
use UnexpectedValueException;

final readonly class SpaceAgentWorkflowInputDataConverter implements PayloadConverterInterface
{
    private const string ENCODING = 'bot/space-agent-workflow-input-v1+json';

    public function __construct(
        private SerializerInterface $telegramSerializer = new Serializer(new Factory()),
    ) {}

    public function getEncodingType(): string
    {
        return self::ENCODING;
    }

    public function toPayload($value): ?Payload
    {
        if (!$value instanceof SpaceAgentWorkflowInput) {
            return null;
        }

        return (new Payload())
            ->setMetadata([EncodingKeys::METADATA_ENCODING_KEY => self::ENCODING])
            ->setData($this->encode($value));
    }

    public function encodedBytes(SpaceAgentWorkflowInput $value): int
    {
        return strlen($this->encode($value));
    }

    public function fromPayload(Payload $payload, Type $type): SpaceAgentWorkflowInput
    {
        $data = json_decode(
            $payload->getData(),
            associative: true,
            flags: \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
        );
        if (!is_array($data)) {
            throw new UnexpectedValueException('Space workflow input payload must be a JSON object.');
        }

        return new SpaceAgentWorkflowInput(
            spaceId: self::string($data, 'spaceId'),
            platform: self::string($data, 'platform'),
            botInstanceId: self::string($data, 'botInstanceId'),
            externalConversationId: self::string($data, 'externalConversationId'),
            externalThreadId: self::nullableString($data, 'externalThreadId'),
            chatId: self::integer($data, 'chatId'),
            chatType: self::string($data, 'chatType'),
            topicId: self::nullableInteger($data, 'topicId'),
            messages: self::array($data, 'messages'),
            processedCount: self::integer($data, 'processedCount'),
            agentRun: self::integer($data, 'agentRun'),
            pipelinePendingSince: self::integer($data, 'pipelinePendingSince'),
            pendingUpdates: $this->deserializePendingUpdates(self::array($data, 'pendingUpdates')),
            paused: self::boolean($data, 'paused'),
            callbackPending: self::boolean($data, 'callbackPending'),
            droppedUpdateCount: self::integer($data, 'droppedUpdateCount'),
            lastNotificationFailure: self::nullableString($data, 'lastNotificationFailure'),
            ingestionFailureCount: self::integer($data, 'ingestionFailureCount'),
            runtimeSnapshotFailureCount: self::integer($data, 'runtimeSnapshotFailureCount'),
            ingestionRetryPending: self::boolean($data, 'ingestionRetryPending'),
            pendingBatchMessageCount: self::integer($data, 'pendingBatchMessageCount'),
            pendingBatchId: self::nullableString($data, 'pendingBatchId'),
            pendingTopicId: self::nullableInteger($data, 'pendingTopicId'),
            pendingActorUserIds: self::integerList($data, 'pendingActorUserIds'),
            pendingActorIdentityComplete: self::boolean($data, 'pendingActorIdentityComplete'),
            pendingRuntimeSnapshot: self::snapshot($data['pendingRuntimeSnapshot'] ?? null),
            pendingTerminalText: self::nullableString($data, 'pendingTerminalText'),
            pendingTerminalScopeId: self::nullableString($data, 'pendingTerminalScopeId'),
            notificationFailureCount: self::integer($data, 'notificationFailureCount'),
        );
    }

    /** @return array<string, mixed> */
    private static function snapshotData(SpaceRuntimeSnapshot $snapshot): array
    {
        return [
            'snapshotId'                 => $snapshot->snapshotId,
            'spaceId'                    => $snapshot->spaceId,
            'releaseId'                  => $snapshot->releaseId,
            'releaseDigest'              => $snapshot->releaseDigest,
            'model'                      => $snapshot->model,
            'systemPrompt'               => $snapshot->systemPrompt,
            'tools'                      => $snapshot->tools,
            'capsuleArtifactRefs'        => $snapshot->capsuleArtifactRefs,
            'capsuleRuntimeImageBuildId' => $snapshot->capsuleRuntimeImageBuildId,
            'memoryRevision'             => $snapshot->memoryRevision,
            'capabilityPolicyRevision'   => $snapshot->capabilityPolicyRevision,
        ];
    }

    private static function snapshot(mixed $value): ?SpaceRuntimeSnapshot
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new UnexpectedValueException('Pending Space runtime snapshot must be an object or null.');
        }

        return new SpaceRuntimeSnapshot(
            snapshotId: self::string($value, 'snapshotId'),
            spaceId: self::string($value, 'spaceId'),
            releaseId: self::string($value, 'releaseId'),
            releaseDigest: self::string($value, 'releaseDigest'),
            model: self::string($value, 'model'),
            systemPrompt: self::string($value, 'systemPrompt'),
            tools: self::array($value, 'tools'),
            capsuleArtifactRefs: self::array($value, 'capsuleArtifactRefs'),
            capsuleRuntimeImageBuildId: self::nullableString($value, 'capsuleRuntimeImageBuildId'),
            memoryRevision: self::string($value, 'memoryRevision'),
            capabilityPolicyRevision: self::string($value, 'capabilityPolicyRevision'),
        );
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException("Space workflow input field {$key} must be a string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new UnexpectedValueException(
                "Space workflow input field {$key} must be a string or null.",
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new UnexpectedValueException("Space workflow input field {$key} must be an integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function nullableInteger(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new UnexpectedValueException(
                "Space workflow input field {$key} must be an integer or null.",
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function boolean(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new UnexpectedValueException("Space workflow input field {$key} must be a boolean.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $key
     *
     * @return array<mixed>
     */
    private static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new UnexpectedValueException("Space workflow input field {$key} must be an array.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $key
     *
     * @return list<int>
     */
    private static function integerList(array $data, string $key): array
    {
        $value = self::array($data, $key);
        foreach ($value as $item) {
            if (!is_int($item)) {
                throw new UnexpectedValueException(
                    "Space workflow input field {$key} must contain only integers.",
                );
            }
        }

        return array_values($value);
    }

    private function encode(SpaceAgentWorkflowInput $value): string
    {
        return json_encode([
            'spaceId'                      => $value->spaceId,
            'platform'                     => $value->platform,
            'botInstanceId'                => $value->botInstanceId,
            'externalConversationId'       => $value->externalConversationId,
            'externalThreadId'             => $value->externalThreadId,
            'chatId'                       => $value->chatId,
            'chatType'                     => $value->chatType,
            'topicId'                      => $value->topicId,
            'messages'                     => $value->messages,
            'processedCount'               => $value->processedCount,
            'agentRun'                     => $value->agentRun,
            'pipelinePendingSince'         => $value->pipelinePendingSince,
            'pendingUpdates'               => $this->serializePendingUpdates($value->pendingUpdates),
            'paused'                       => $value->paused,
            'callbackPending'              => $value->callbackPending,
            'droppedUpdateCount'           => $value->droppedUpdateCount,
            'lastNotificationFailure'      => $value->lastNotificationFailure,
            'ingestionFailureCount'        => $value->ingestionFailureCount,
            'runtimeSnapshotFailureCount'  => $value->runtimeSnapshotFailureCount,
            'ingestionRetryPending'        => $value->ingestionRetryPending,
            'pendingBatchMessageCount'     => $value->pendingBatchMessageCount,
            'pendingBatchId'               => $value->pendingBatchId,
            'pendingTopicId'               => $value->pendingTopicId,
            'pendingActorUserIds'          => $value->pendingActorUserIds,
            'pendingActorIdentityComplete' => $value->pendingActorIdentityComplete,
            'pendingRuntimeSnapshot'       => $value->pendingRuntimeSnapshot === null
                ? null
                : self::snapshotData($value->pendingRuntimeSnapshot),
            'pendingTerminalText'      => $value->pendingTerminalText,
            'pendingTerminalScopeId'   => $value->pendingTerminalScopeId,
            'notificationFailureCount' => $value->notificationFailureCount,
        ], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param list<QueuedSpaceUpdate> $pendingUpdates
     *
     * @return list<array{update: array<string, mixed>, appendToAgent: bool, ingestionId: string}>
     */
    private function serializePendingUpdates(array $pendingUpdates): array
    {
        $payloads = [];
        foreach ($pendingUpdates as $index => $pendingUpdate) {
            if (!$pendingUpdate instanceof QueuedSpaceUpdate) {
                throw new UnexpectedValueException(
                    sprintf('Pending Space update %d is not a queued update envelope.', $index),
                );
            }
            $serialized = $this->telegramSerializer->serialize([$pendingUpdate->update]);
            $update     = $serialized[0] ?? null;
            if (!is_array($update)) {
                throw new UnexpectedValueException(
                    sprintf('Pending Space update %d did not serialize to an object.', $index),
                );
            }
            $payloads[] = [
                'update'        => $update,
                'appendToAgent' => $pendingUpdate->appendToAgent,
                'ingestionId'   => $pendingUpdate->ingestionId,
            ];
        }

        return $payloads;
    }

    /**
     * @param list<mixed> $payloads
     *
     * @return list<QueuedSpaceUpdate>
     */
    private function deserializePendingUpdates(array $payloads): array
    {
        $pendingUpdates = [];
        foreach ($payloads as $index => $payload) {
            if (!is_array($payload) || !is_array($payload['update'] ?? null)) {
                throw new UnexpectedValueException(
                    sprintf('Pending Space update %d must contain a serialized update object.', $index),
                );
            }
            $appendToAgent = $payload['appendToAgent'] ?? null;
            $ingestionId   = $payload['ingestionId'] ?? null;
            if (!is_bool($appendToAgent) || !is_string($ingestionId) || $ingestionId === '') {
                throw new UnexpectedValueException(
                    sprintf('Pending Space update %d has invalid envelope state.', $index),
                );
            }

            $decoded = $this->telegramSerializer->deserialize(
                [$payload['update']],
                UpdateInterface::class,
                isArray: true,
            );
            $update = is_array($decoded) ? ($decoded[0] ?? null) : null;
            if (!$update instanceof Update) {
                throw new UnexpectedValueException(
                    sprintf('Pending Space update %d decoded to an unsupported type.', $index),
                );
            }
            $pendingUpdates[] = new QueuedSpaceUpdate($update, $appendToAgent, $ingestionId);
        }

        return $pendingUpdates;
    }
}
