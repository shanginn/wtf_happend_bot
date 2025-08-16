<?php

declare(strict_types=1);

namespace Bot\Service;

use Mem0\DTO\Filter;
use Mem0\DTO\Memory;
use Mem0\DTO\Message as Mem0Message;
use Mem0\Enum\Role;
use Mem0\Mem0;
use Shanginn\Openai\ChatCompletion\Message\AssistantMessage;
use Shanginn\Openai\ChatCompletion\Message\SystemMessage;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;

class MemoryService
{
    private const APP_ID = 'wtf-happened-bot';
    private const AGENT_ID = 'wtf-telegram-bot';

    public function __construct(
        private readonly Mem0 $mem0,
    ) {}

    /**
     * Search for relevant memories based on a query
     *
     * @param string $query
     * @param int    $chatId
     * @param int    $userId
     *
     * @return array<Memory>
     */
    public function searchMemories(string $query, int $chatId, int $userId): array
    {
        return $this->mem0->search(
            query: $query,
            filters: new Filter(
                and: [
                    ['user_id' => $this->getUserId($chatId, $userId)],
                    ['app_id' => self::APP_ID],
                ],
            )
        );
    }

    /**
     * Add new memories from conversation messages
     *
     * @param array $messages Array of messages in format [['role' => 'user|assistant|system', 'content' => 'text'], ...]
     * @param int   $chatId
     * @param int   $userId
     *
     * @return array Results from memory addition
     */
    public function addMemories(array $messages, int $chatId, int $userId): array
    {
        $mem0Messages = $this->convertToMem0Messages($messages);

        return $this->mem0->add(
            messages: $mem0Messages,
            agentId: self::AGENT_ID,
            userId: $this->getUserId($chatId, $userId),
            appId: self::APP_ID,
        );
    }

    /**
     * Convert array of messages to OpenAI format
     *
     * @param array $messages
     *
     * @return array
     */
    public function convertToOpenaiMessages(array $messages): array
    {
        return array_map(function ($message) {
            return match ($message['role']) {
                'system' => new SystemMessage($message['content']),
                'user' => new UserMessage($message['content']),
                'assistant' => new AssistantMessage($message['content']),
                default => throw new \InvalidArgumentException("Unknown role: {$message['role']}"),
            };
        }, $messages);
    }

    /**
     * Get memories formatted as a string for system prompts
     *
     * @param array<Memory> $memories
     *
     * @return string
     */
    public function formatMemoriesForPrompt(array $memories): string
    {
        if (count($memories) === 0) {
            return "No relevant memories found.";
        }

        return implode("\n", array_map(
            fn(Memory $entry) => "- {$entry->memory}",
            $memories
        ));
    }

    /**
     * Convert messages to Mem0 format
     *
     * @param array $messages
     *
     * @return array<Mem0Message>
     */
    private function convertToMem0Messages(array $messages): array
    {
        return array_map(function ($message) {
            return new Mem0Message(
                role: Role::from($message['role']),
                content: $message['content']
            );
        }, $messages);
    }

    /**
     * Generate unique user ID for memory system
     *
     * @param int $chatId
     * @param int $userId
     *
     * @return string
     */
    private function getUserId(int $chatId, int $userId): string
    {
        return "{$chatId}-{$userId}";
    }
}