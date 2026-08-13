<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Llm\Tools\Telegram\TelegramApiCallExecutor;
use Bot\Telegram\TelegramTypingRefresher;
use PiPHP\Temporal\Contract\ModelCompletionGatewayInterface;
use PiPHP\Temporal\DTO\ModelActivityInput;
use PiPHP\Temporal\DTO\ModelActivityResult;
use UnexpectedValueException;

/**
 * Adds transient Telegram typing UI only after the host can prove that this
 * model turn is composing a reply. Ordinary PiPH turns prove that with the
 * nonterminal commit_to_reply tool; a resolved Space slash command is already
 * committed by its host-owned command route.
 */
final readonly class ReplyTypingModelGateway implements ModelCompletionGatewayInterface
{
    public function __construct(
        private ModelCompletionGatewayInterface $inner,
        private TelegramTypingRefresher $typing,
    ) {}

    public function complete(ModelActivityInput $input): ModelActivityResult
    {
        $route     = self::messageRoute($input);
        $command   = self::isSpaceCommand($input);
        $committed = self::hasCurrentReplyCommitment($input->messages);
        $complete  = fn (): ModelActivityResult => $this->inner->complete($input);

        if ($command || $committed) {
            if ($route === null) {
                throw new UnexpectedValueException(
                    'A committed Telegram reply requires a valid integer chat and topic route.',
                );
            }

            return $this->typing->whileTyping(
                chatId: $route['chatId'],
                topicId: $route['topicId'],
                operation: $complete,
            );
        }

        return self::requireReplyCommitment($input, $complete());
    }

    /** @return array{chatId: int, topicId: ?int}|null */
    private static function messageRoute(ModelActivityInput $input): ?array
    {
        $chatId  = $input->metadata['chatId'] ?? null;
        $topicId = $input->metadata['topicId'] ?? null;
        if (!is_int($chatId) || $chatId === 0 || (!is_int($topicId) && $topicId !== null)) {
            return null;
        }

        return ['chatId' => $chatId, 'topicId' => $topicId];
    }

    private static function isSpaceCommand(ModelActivityInput $input): bool
    {
        $spaceCommand = $input->metadata['spaceCommand'] ?? null;

        return is_string($spaceCommand) && $spaceCommand !== '';
    }

    /** @param list<array<string, mixed>> $messages */
    private static function hasCurrentReplyCommitment(array $messages): bool
    {
        $latestUserIndex = null;
        for ($index = count($messages) - 1; $index >= 0; --$index) {
            if (($messages[$index]['role'] ?? null) === 'user') {
                $latestUserIndex = $index;

                break;
            }
        }
        if ($latestUserIndex === null) {
            return false;
        }

        for ($index = $latestUserIndex + 1, $count = count($messages); $index < $count; ++$index) {
            $message = $messages[$index];
            if (
                ($message['role'] ?? null) === 'toolResult'
                && ($message['toolName'] ?? null) === 'commit_to_reply'
                && ($message['isError'] ?? true) === false
            ) {
                return true;
            }
        }

        return false;
    }

    private static function requireReplyCommitment(
        ModelActivityInput $input,
        ModelActivityResult $result,
    ): ModelActivityResult {
        if ($result->errorMessage !== null || in_array($result->stopReason, ['error', 'aborted'], true)) {
            return $result;
        }

        $hasCommitmentCall = false;
        $hasTerminalCall   = false;
        foreach ($result->toolCalls as $call) {
            if ($call['name'] === 'commit_to_reply') {
                $hasCommitmentCall = true;

                continue;
            }

            $method = $call['arguments']['method'] ?? null;
            if (
                $call['name'] === 'telegram_api_call'
                && is_string($method)
                && TelegramApiCallExecutor::isTerminalMethod($method)
            ) {
                $hasTerminalCall = true;
            }
        }

        if (
            !$hasCommitmentCall
            && !$hasTerminalCall
            && ($result->toolCalls !== [] || !self::hasVisibleText($result->assistantMessage))
        ) {
            return $result;
        }

        $call = [
            'id'        => 'reply-commit-' . substr(hash('sha256', $input->idempotencyKey), 0, 24),
            'name'      => 'commit_to_reply',
            'arguments' => [],
        ];

        // DeepSeek thinking-mode tool turns must replay reasoning_content verbatim.
        $content = [];
        foreach ($result->assistantMessage['content'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'thinking') {
                $content[] = $block;
            }
        }
        $content[] = ['type' => 'toolCall', ...$call];

        return new ModelActivityResult(
            assistantMessage: [
                ...$result->assistantMessage,
                'role'         => 'assistant',
                'content'      => $content,
                'stopReason'   => 'tool_use',
                'errorMessage' => null,
            ],
            toolCalls: [$call],
            stopReason: 'tool_use',
            usage: $result->usage,
        );
    }

    /** @param array<string, mixed> $message */
    private static function hasVisibleText(array $message): bool
    {
        $content = $message['content'] ?? null;
        if (!is_array($content)) {
            return false;
        }

        foreach ($content as $block) {
            if (
                is_array($block)
                && ($block['type'] ?? null) === 'text'
                && is_string($block['text'] ?? null)
                && trim($block['text']) !== ''
            ) {
                return true;
            }
        }

        return false;
    }
}
