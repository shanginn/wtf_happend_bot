<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use InvalidArgumentException;

final readonly class AgenticWorkflowInput
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     * @param list<QueuedTelegramUpdate> $pendingUpdates
     * @param list<int>                  $pendingActorUserIds
     * @param int                        $chatId
     * @param string                     $chatType
     * @param string                     $model
     * @param ?int                       $topicId
     * @param int                        $processedCount
     * @param int                        $agentRun
     * @param int                        $pipelinePendingSince
     * @param bool                       $paused
     * @param bool                       $callbackPending
     * @param int                        $droppedUpdateCount
     * @param ?string                    $lastNotificationFailure
     * @param int                        $ingestionFailureCount
     * @param int                        $contextFailureCount
     * @param bool                       $ingestionRetryPending
     * @param int                        $pendingBatchMessageCount
     * @param bool                       $pendingActorIdentityComplete
     * @param ?string                    $pendingTerminalText
     * @param ?string                    $pendingTerminalScopeId
     * @param int                        $notificationFailureCount
     */
    public function __construct(
        public int $chatId,
        public string $chatType,
        public string $model,
        public array $tools,
        public ?int $topicId = null,
        public array $messages = [],
        public int $processedCount = 0,
        public int $agentRun = 0,
        public int $pipelinePendingSince = 0,
        public array $pendingUpdates = [],
        public bool $paused = false,
        public bool $callbackPending = false,
        public int $droppedUpdateCount = 0,
        public ?string $lastNotificationFailure = null,
        public int $ingestionFailureCount = 0,
        public int $contextFailureCount = 0,
        public bool $ingestionRetryPending = false,
        public int $pendingBatchMessageCount = 0,
        public array $pendingActorUserIds = [],
        public bool $pendingActorIdentityComplete = true,
        public ?string $pendingTerminalText = null,
        public ?string $pendingTerminalScopeId = null,
        public int $notificationFailureCount = 0,
    ) {
        if (!in_array($chatType, ['private', 'group', 'supergroup', 'channel'], true)) {
            throw new InvalidArgumentException('Telegram chat type is unsupported.');
        }

        foreach ($pendingUpdates as $index => $pendingUpdate) {
            if (!$pendingUpdate instanceof QueuedTelegramUpdate) {
                throw new InvalidArgumentException(
                    sprintf('Pending Telegram update %d must be a queued update envelope.', $index),
                );
            }
        }

        if (
            $ingestionFailureCount < 0
            || $contextFailureCount < 0
            || $pendingBatchMessageCount < 0
            || $notificationFailureCount < 0
        ) {
            throw new InvalidArgumentException('Workflow counters cannot be negative.');
        }

        if (($pendingTerminalText === null) !== ($pendingTerminalScopeId === null)) {
            throw new InvalidArgumentException(
                'Pending terminal notification text and scope must be stored together.',
            );
        }
        if (
            $pendingTerminalText !== null
            && (trim($pendingTerminalText) === '' || $pendingTerminalScopeId === '')
        ) {
            throw new InvalidArgumentException(
                'Pending terminal notification text and scope must be non-empty.',
            );
        }
        if ($pendingTerminalText !== null && $pendingBatchMessageCount === 0) {
            throw new InvalidArgumentException(
                'A pending terminal notification requires a pending agent batch.',
            );
        }
        if ($pendingTerminalText === null && $notificationFailureCount !== 0) {
            throw new InvalidArgumentException(
                'Notification failures require a pending terminal notification.',
            );
        }

        if (!array_is_list($pendingActorUserIds)) {
            throw new InvalidArgumentException(
                'Pending Telegram actor IDs must be a list.',
            );
        }

        $previousActorId = 0;
        foreach ($pendingActorUserIds as $actorUserId) {
            if (!is_int($actorUserId) || $actorUserId <= $previousActorId) {
                throw new InvalidArgumentException(
                    'Pending Telegram actor IDs must be strictly increasing positive integers.',
                );
            }

            $previousActorId = $actorUserId;
        }

        $messageCount = count($messages);
        if (($messages[0]['role'] ?? null) === 'system') {
            --$messageCount;
        }
        if ($pendingBatchMessageCount > $messageCount) {
            throw new InvalidArgumentException(
                'Pending agent batch cannot contain more messages than workflow history.',
            );
        }
        if ($pendingBatchMessageCount === 0 && $pendingActorUserIds !== []) {
            throw new InvalidArgumentException(
                'Pending Telegram actors require a pending agent batch.',
            );
        }
        if ($pendingBatchMessageCount === 0 && !$pendingActorIdentityComplete) {
            throw new InvalidArgumentException(
                'Telegram actor identity state must reset between agent batches.',
            );
        }
        if (
            $pendingBatchMessageCount > 0
            && $pendingActorIdentityComplete
            && $pendingActorUserIds === []
        ) {
            throw new InvalidArgumentException(
                'A complete pending actor identity set cannot be empty.',
            );
        }
    }
}
