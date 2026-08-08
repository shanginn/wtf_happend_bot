<?php

declare(strict_types=1);

namespace Bot\Temporal;

use Bot\AgenticWorkflow\AgenticWorkflowInput;
use Bot\AgenticWorkflow\QueuedTelegramUpdate;
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

final readonly class AgenticWorkflowInputDataConverter implements PayloadConverterInterface
{
    private const string ENCODING = 'bot/agentic-workflow-input+json';

    public function __construct(
        private SerializerInterface $telegramSerializer = new Serializer(new Factory()),
    ) {}

    public function getEncodingType(): string
    {
        return self::ENCODING;
    }

    public function toPayload($value): ?Payload
    {
        if (!$value instanceof AgenticWorkflowInput) {
            return null;
        }

        return (new Payload())
            ->setMetadata([
                EncodingKeys::METADATA_ENCODING_KEY => self::ENCODING,
            ])
            ->setData($this->encode($value));
    }

    public function encodedBytes(AgenticWorkflowInput $value): int
    {
        return strlen($this->encode($value));
    }

    public function fromPayload(Payload $payload, Type $type): AgenticWorkflowInput
    {
        $data = json_decode(
            $payload->getData(),
            associative: true,
            flags: \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
        );

        if (!is_array($data)) {
            throw new UnexpectedValueException('Agentic workflow input payload must be a JSON object.');
        }

        $pendingUpdates = $this->deserializePendingUpdates(
            $this->arrayValue($data, 'pendingUpdates'),
        );

        return new AgenticWorkflowInput(
            chatId: $this->integerValue($data, 'chatId'),
            chatType: $this->stringValue($data, 'chatType'),
            topicId: $this->nullableIntegerValue($data, 'topicId'),
            model: $this->stringValue($data, 'model'),
            tools: $this->arrayValue($data, 'tools'),
            messages: $this->arrayValue($data, 'messages'),
            processedCount: $this->integerValue($data, 'processedCount'),
            agentRun: $this->integerValue($data, 'agentRun'),
            pipelinePendingSince: $this->integerValue($data, 'pipelinePendingSince'),
            pendingUpdates: $pendingUpdates,
            paused: $this->booleanValue($data, 'paused'),
            callbackPending: $this->booleanValue($data, 'callbackPending'),
            droppedUpdateCount: $this->integerValue($data, 'droppedUpdateCount'),
            lastNotificationFailure: $this->nullableStringValue($data, 'lastNotificationFailure'),
            ingestionFailureCount: $this->integerValue($data, 'ingestionFailureCount'),
            contextFailureCount: $this->integerValue($data, 'contextFailureCount'),
            ingestionRetryPending: $this->booleanValue($data, 'ingestionRetryPending'),
            pendingBatchMessageCount: $this->integerValue($data, 'pendingBatchMessageCount'),
            pendingActorUserIds: $this->integerListValue($data, 'pendingActorUserIds'),
            pendingActorIdentityComplete: $this->booleanValue(
                $data,
                'pendingActorIdentityComplete',
            ),
            pendingTerminalText: $this->nullableStringValue($data, 'pendingTerminalText'),
            pendingTerminalScopeId: $this->nullableStringValue(
                $data,
                'pendingTerminalScopeId',
            ),
            notificationFailureCount: $this->integerValue(
                $data,
                'notificationFailureCount',
            ),
        );
    }

    private function encode(AgenticWorkflowInput $value): string
    {
        return json_encode([
            'chatId'                       => $value->chatId,
            'chatType'                     => $value->chatType,
            'topicId'                      => $value->topicId,
            'model'                        => $value->model,
            'tools'                        => $value->tools,
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
            'contextFailureCount'          => $value->contextFailureCount,
            'ingestionRetryPending'        => $value->ingestionRetryPending,
            'pendingBatchMessageCount'     => $value->pendingBatchMessageCount,
            'pendingActorUserIds'          => $value->pendingActorUserIds,
            'pendingActorIdentityComplete' => $value->pendingActorIdentityComplete,
            'pendingTerminalText'          => $value->pendingTerminalText,
            'pendingTerminalScopeId'       => $value->pendingTerminalScopeId,
            'notificationFailureCount'     => $value->notificationFailureCount,
        ], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param list<QueuedTelegramUpdate> $pendingUpdates
     *
     * @return list<array{update: array<string, mixed>, appendToAgent: bool, ingestionId: string}>
     */
    private function serializePendingUpdates(array $pendingUpdates): array
    {
        $payloads = [];
        foreach ($pendingUpdates as $index => $pendingUpdate) {
            if (!$pendingUpdate instanceof QueuedTelegramUpdate) {
                throw new UnexpectedValueException(
                    sprintf('Pending Telegram update %d is not a queued update envelope.', $index),
                );
            }

            $serialized = $this->telegramSerializer->serialize([$pendingUpdate->update]);
            $update     = $serialized[0] ?? null;
            if (!is_array($update)) {
                throw new UnexpectedValueException(
                    sprintf('Pending Telegram update %d did not serialize to an object.', $index),
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
     * @return list<QueuedTelegramUpdate>
     */
    private function deserializePendingUpdates(array $payloads): array
    {
        $pendingUpdates = [];
        foreach ($payloads as $index => $payload) {
            if (!is_array($payload) || !is_array($payload['update'] ?? null)) {
                throw new UnexpectedValueException(
                    sprintf('Pending Telegram update %d must contain a serialized update object.', $index),
                );
            }
            $appendToAgent = $payload['appendToAgent'] ?? null;
            if (!is_bool($appendToAgent)) {
                throw new UnexpectedValueException(
                    sprintf('Pending Telegram update %d must contain appendToAgent boolean state.', $index),
                );
            }
            $ingestionId = $payload['ingestionId'] ?? null;
            if (!is_string($ingestionId) || $ingestionId === '') {
                throw new UnexpectedValueException(
                    sprintf('Pending Telegram update %d must contain a non-empty ingestion ID.', $index),
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
                    sprintf('Pending Telegram update %d decoded to an unsupported type.', $index),
                );
            }

            $pendingUpdates[] = new QueuedTelegramUpdate($update, $appendToAgent, $ingestionId);
        }

        return $pendingUpdates;
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $key
     *
     * @return array<mixed>
     */
    private function arrayValue(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new UnexpectedValueException("Agentic workflow input field {$key} must be an array.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $key
     */
    private function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException("Agentic workflow input field {$key} must be a string.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $key
     */
    private function integerValue(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new UnexpectedValueException("Agentic workflow input field {$key} must be an integer.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $key
     */
    private function booleanValue(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new UnexpectedValueException("Agentic workflow input field {$key} must be a boolean.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $key
     */
    private function nullableIntegerValue(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new UnexpectedValueException("Agentic workflow input field {$key} must be an integer or null.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $key
     */
    private function nullableStringValue(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new UnexpectedValueException("Agentic workflow input field {$key} must be a string or null.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $key
     *
     * @return list<int>
     */
    private function integerListValue(array $data, string $key): array
    {
        $value = $this->arrayValue($data, $key);
        foreach ($value as $item) {
            if (!is_int($item)) {
                throw new UnexpectedValueException(
                    "Agentic workflow input field {$key} must contain only integers.",
                );
            }
        }

        return array_values($value);
    }
}
