<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Llm\Tools\Image\DownloadImage;
use Bot\Openai\CompatibleOpenaiSerializer;
use Bot\Telegram\Update;
use Shanginn\Openai\ChatCompletion\CompletionRequest\ToolInterface;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Temporal\Internal\Marshaller\Meta\MarshalArray;

class AgenticWorkflowInput
{
    private const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    /**
     * @param array<int, MessageInterface|array<string, mixed>> $workingMemory
     * @param Update[] $pendingUpdates
     */
    public function __construct(
        public int $chatId,
        public int $processedCount = 0,
        public array $workingMemory = [],
        public string $compactedContext = '',
        public int $lastActivityAt = 0,
        public int $lastCompactionAt = 0,
        public int $compactionRetryAfter = 0,
        public int $consecutiveCompactionFailures = 0,
        public int $pipelinePendingSince = 0,
        #[MarshalArray(of: Update::class)]
        public array $pendingUpdates = [],
        public bool $paused = false,
    ) {}

    public function getPendingUpdates(): array
    {
        return isset($this->pendingUpdates) ? $this->pendingUpdates : [];
    }

    public function isPaused(): bool
    {
        return isset($this->paused) && $this->paused;
    }

    /**
     * @return MessageInterface[]
     */
    public function getWorkingMemory(): array
    {
        return self::hydrateWorkingMemory($this->workingMemory);
    }

    /**
     * @param MessageInterface[] $messages
     * @return array<int, array<string, mixed>>
     */
    public static function serializeWorkingMemory(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        $payload = json_decode(
            self::openaiSerializer()->serialize($messages),
            associative: true,
            flags: self::JSON_FLAGS,
        );

        if (!is_array($payload)) {
            throw new \UnexpectedValueException('Serialized working memory must be a JSON array.');
        }

        return $payload;
    }

    /**
     * @param array<int, MessageInterface|array<string, mixed>|object> $payloads
     * @return MessageInterface[]
     */
    public static function hydrateWorkingMemory(array $payloads): array
    {
        if ($payloads === []) {
            return [];
        }

        if (self::allMessages($payloads)) {
            return array_values($payloads);
        }

        $payloads = array_map(
            static fn (mixed $payload): array => self::normalizeMessagePayload($payload),
            $payloads,
        );

        $messages = self::openaiSerializer()->deserialize(
            json_encode($payloads, self::JSON_FLAGS),
            'array',
            self::knownTools(),
        );

        foreach ($messages as $message) {
            if (!$message instanceof MessageInterface) {
                throw new \UnexpectedValueException('Hydrated working memory must contain OpenAI messages.');
            }
        }

        return array_values($messages);
    }

    /**
     * @param array<int, mixed> $payloads
     */
    private static function allMessages(array $payloads): bool
    {
        foreach ($payloads as $payload) {
            if (!$payload instanceof MessageInterface) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeMessagePayload(mixed $payload): array
    {
        if ($payload instanceof MessageInterface) {
            return self::serializeWorkingMemory([$payload])[0];
        }

        $payload = self::toArray($payload);

        if (!is_array($payload)) {
            throw new \UnexpectedValueException('Working memory message payload must be an array.');
        }

        if (isset($payload['role'])) {
            $payload['role'] = self::enumValue($payload['role']);
        }

        self::renameKey($payload, 'reasoningContent', 'reasoning_content');
        self::renameKey($payload, 'toolCallId', 'tool_call_id');
        self::renameKey($payload, 'toolCalls', 'tool_calls');

        if (isset($payload['tool_calls']) && is_array($payload['tool_calls'])) {
            $payload['tool_calls'] = array_map(
                static fn (mixed $toolCall): array => self::normalizeToolCallPayload($toolCall),
                $payload['tool_calls'],
            );
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeToolCallPayload(mixed $payload): array
    {
        $toolCall = self::toArray($payload);

        if (!is_array($toolCall)) {
            throw new \UnexpectedValueException('Tool call payload must be an array.');
        }

        $type = self::enumValue($toolCall['type'] ?? 'function');

        if (isset($toolCall['function'])) {
            $function = self::toArray($toolCall['function']);
            if (!is_array($function)) {
                throw new \UnexpectedValueException('Tool call function payload must be an array.');
            }

            $arguments = $function['arguments'] ?? '{}';
            if (!is_string($arguments)) {
                $arguments = json_encode($arguments, self::JSON_FLAGS);
            }

            return [
                'id' => (string) ($toolCall['id'] ?? ''),
                'type' => is_string($type) ? $type : 'function',
                'function' => [
                    'name' => (string) ($function['name'] ?? ''),
                    'arguments' => $arguments,
                ],
            ];
        }

        $name = $toolCall['name'] ?? null;
        $tool = $toolCall['tool'] ?? null;
        if (!is_string($name) && is_string($tool) && is_a($tool, ToolInterface::class, true)) {
            $name = $tool::getName();
        }

        $arguments = $toolCall['arguments'] ?? '{}';
        if (!is_string($arguments)) {
            $arguments = json_encode($arguments, self::JSON_FLAGS);
        }

        return [
            'id' => (string) ($toolCall['id'] ?? ''),
            'type' => is_string($type) ? $type : 'function',
            'function' => [
                'name' => (string) $name,
                'arguments' => $arguments,
            ],
        ];
    }

    private static function renameKey(array &$payload, string $from, string $to): void
    {
        if (array_key_exists($from, $payload) && !array_key_exists($to, $payload)) {
            $payload[$to] = $payload[$from];
            unset($payload[$from]);
        }
    }

    private static function enumValue(mixed $value): mixed
    {
        $value = self::toArray($value);

        if (is_array($value) && array_key_exists('value', $value)) {
            return $value['value'];
        }

        return $value;
    }

    private static function toArray(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(
                static fn (mixed $item): mixed => self::toArray($item),
                $value,
            );
        }

        if (is_object($value)) {
            return self::toArray(get_object_vars($value));
        }

        return $value;
    }

    private static function openaiSerializer(): CompatibleOpenaiSerializer
    {
        return new CompatibleOpenaiSerializer();
    }

    /**
     * @return array<class-string<ToolInterface>>
     */
    private static function knownTools(): array
    {
        return [
            DownloadImage::class,
            ...AgenticToolset::TOOLS,
        ];
    }
}
