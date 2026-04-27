<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Shanginn\Openai\ChatCompletion\Message\AssistantMessage;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Shanginn\Openai\ChatCompletion\Message\SystemMessage;
use Shanginn\Openai\ChatCompletion\Message\ToolMessage;
use Temporal\Internal\Marshaller\Meta\MarshalArray;

class WorkingMemory
{
    private const int COMPACTION_BATCH_TOKEN_BUDGET = 30000;
    private const int ESTIMATED_CHARS_PER_TOKEN = 4;
    private const int LIMIT = 200;
    private const int RECENT_MESSAGES_TO_KEEP = 50;
    private const string COMPACTED_CONTEXT_PREFIX = 'Compacted context from earlier conversation:';

    public function __construct(
        #[MarshalArray(of: MessageInterface::class)]
        private array $memories = [],
        private string $compactedContext = '',
    )
    {
    }

    public function add(MessageInterface $message): void
    {
        $this->memories[] = $message;
    }

    /**
     * @return MessageInterface[]
     */
    public function get(): array
    {
        return $this->memories;
    }

    /**
     * @return MessageInterface[]
     */
    public function getRecent(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $start = max(0, count($this->memories) - $limit);

        return $this->sanitizeForChatCompletion(
            array_slice($this->memories, $this->safeBoundaryIndex($start)),
        );
    }

    /**
     * @return MessageInterface[]
     */
    public function getContext(?int $recentLimit = null): array
    {
        $messages = $recentLimit === null
            ? $this->sanitizeForChatCompletion($this->memories)
            : $this->getRecent($recentLimit);

        $compactedContext = self::normalizeCompactedContext($this->compactedContext);
        if ($compactedContext === '') {
            return $messages;
        }

        array_unshift(
            $messages,
            new SystemMessage(self::COMPACTED_CONTEXT_PREFIX . "\n" . $compactedContext),
        );

        return $messages;
    }

    public function getCompactedContext(): string
    {
        return self::normalizeCompactedContext($this->compactedContext);
    }

    /**
     * @return MessageInterface[]
     */
    public function getMessagesToCompact(): array
    {
        if (!$this->hasMessagesToCompact()) {
            return [];
        }

        return $this->sanitizeForChatCompletion(
            array_slice($this->memories, 0, $this->compactionBatchBoundaryIndex()),
        );
    }

    public function hasMessagesToCompact(): bool
    {
        return $this->compactionBoundaryIndex() > 0;
    }

    public function compact(string $compactedContext): void
    {
        $this->compactedContext = self::normalizeCompactedContext($compactedContext);
        $boundaryIndex = $this->compactionBoundaryIndex();

        if ($boundaryIndex === 0) {
            return;
        }

        $this->memories = array_slice($this->memories, $boundaryIndex);
    }

    public function dropMessagesToCompact(): void
    {
        $boundaryIndex = $this->compactionBoundaryIndex();

        if ($boundaryIndex === 0) {
            return;
        }

        $this->memories = array_slice($this->memories, $boundaryIndex);
    }

    public function shouldCompactBySize(): bool
    {
        return count($this->memories) >= self::LIMIT;
    }

    private function compactionBoundaryIndex(): int
    {
        return $this->safeBoundaryIndex(max(0, count($this->memories) - self::RECENT_MESSAGES_TO_KEEP));
    }

    private function compactionBatchBoundaryIndex(): int
    {
        $boundaryIndex = $this->compactionBoundaryIndex();
        $tokens = 0;

        for ($index = 0; $index < $boundaryIndex; ++$index) {
            $tokens += self::estimateTokens($this->memories[$index]);

            if ($tokens > self::COMPACTION_BATCH_TOKEN_BUDGET) {
                return $this->safeBoundaryIndex($index);
            }
        }

        return $boundaryIndex;
    }

    private function safeBoundaryIndex(int $index): int
    {
        while ($index > 0 && ($this->memories[$index] ?? null) instanceof ToolMessage) {
            --$index;
        }

        return $index;
    }

    /**
     * OpenAI chat history must not contain orphan tool messages or incomplete
     * assistant tool-call groups. Keep full groups only.
     *
     * @param MessageInterface[] $messages
     * @return MessageInterface[]
     */
    private function sanitizeForChatCompletion(array $messages): array
    {
        $result = [];
        $pendingAssistant = null;
        $pendingTools = [];
        $pendingToolCallIds = [];

        foreach ($messages as $message) {
            if ($pendingAssistant instanceof AssistantMessage) {
                if ($message instanceof ToolMessage && isset($pendingToolCallIds[$message->toolCallId])) {
                    $pendingTools[] = $message;
                    unset($pendingToolCallIds[$message->toolCallId]);

                    if ($pendingToolCallIds === []) {
                        $result[] = $pendingAssistant;
                        array_push($result, ...$pendingTools);
                        $pendingAssistant = null;
                        $pendingTools = [];
                    }

                    continue;
                }

                $pendingAssistant = null;
                $pendingTools = [];
                $pendingToolCallIds = [];
            }

            if ($message instanceof ToolMessage) {
                continue;
            }

            $toolCallIds = self::toolCallIds($message);
            if ($toolCallIds !== []) {
                $pendingAssistant = $message;
                $pendingToolCallIds = array_fill_keys($toolCallIds, true);
                continue;
            }

            $result[] = $message;
        }

        return $result;
    }

    private static function normalizeCompactedContext(string $compactedContext): string
    {
        $normalized = trim($compactedContext);

        if ($normalized === '' || mb_strtolower($normalized) === 'no compacted context.') {
            return '';
        }

        return $normalized;
    }

    /**
     * @return string[]
     */
    private static function toolCallIds(MessageInterface $message): array
    {
        if (!$message instanceof AssistantMessage || $message->toolCalls === null) {
            return [];
        }

        $ids = [];

        foreach ($message->toolCalls as $toolCall) {
            $id = $toolCall->id ?? null;

            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private static function estimateTokens(MessageInterface $message): int
    {
        $payload = json_encode($message, \JSON_THROW_ON_ERROR);

        return max(1, (int) ceil(strlen($payload) / self::ESTIMATED_CHARS_PER_TOKEN));
    }
}
