<?php

declare(strict_types=1);

namespace Bot\Space\Workflow;

use Bot\Space\Runtime\SpaceIdentity;
use Bot\Space\Runtime\SpaceRuntimeSnapshot;
use InvalidArgumentException;

/**
 * Complete continue-as-new state for SpaceAgentWorkflowV1. This is a clean-cut
 * contract and intentionally has no legacy AgenticWorkflow payload support.
 */
final readonly class SpaceAgentWorkflowInput
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<QueuedSpaceUpdate>    $pendingUpdates
     * @param list<int>                  $pendingActorUserIds
     * @param string                     $spaceId
     * @param string                     $platform
     * @param string                     $botInstanceId
     * @param string                     $externalConversationId
     * @param ?string                    $externalThreadId
     * @param int                        $chatId
     * @param string                     $chatType
     * @param ?int                       $topicId
     * @param string                     $botUsername
     * @param int                        $processedCount
     * @param int                        $agentRun
     * @param int                        $pipelinePendingSince
     * @param bool                       $paused
     * @param bool                       $callbackPending
     * @param int                        $droppedUpdateCount
     * @param ?string                    $lastNotificationFailure
     * @param int                        $ingestionFailureCount
     * @param int                        $runtimeSnapshotFailureCount
     * @param bool                       $ingestionRetryPending
     * @param int                        $pendingBatchMessageCount
     * @param ?string                    $pendingBatchId
     * @param ?int                       $pendingTopicId
     * @param ?SpaceCommandInvocation    $pendingCommandInvocation
     * @param bool                       $pendingActorIdentityComplete
     * @param ?SpaceRuntimeSnapshot      $pendingRuntimeSnapshot
     * @param ?string                    $pendingTerminalText
     * @param ?string                    $pendingTerminalScopeId
     * @param int                        $notificationFailureCount
     * @param int                        $lastSpontaneousReplyAt
     * @param int                        $humanUpdatesSinceSpontaneousReply
     */
    public function __construct(
        public string $spaceId,
        public string $platform,
        public string $botInstanceId,
        public string $externalConversationId,
        public ?string $externalThreadId,
        public int $chatId,
        public string $chatType,
        public ?int $topicId,
        public string $botUsername,
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
        public int $runtimeSnapshotFailureCount = 0,
        public bool $ingestionRetryPending = false,
        public int $pendingBatchMessageCount = 0,
        public ?string $pendingBatchId = null,
        public ?int $pendingTopicId = null,
        public ?SpaceCommandInvocation $pendingCommandInvocation = null,
        public array $pendingActorUserIds = [],
        public bool $pendingActorIdentityComplete = true,
        public ?SpaceRuntimeSnapshot $pendingRuntimeSnapshot = null,
        public ?string $pendingTerminalText = null,
        public ?string $pendingTerminalScopeId = null,
        public int $notificationFailureCount = 0,
        public int $lastSpontaneousReplyAt = 0,
        public int $humanUpdatesSinceSpontaneousReply = 0,
    ) {
        new SpaceIdentity(
            spaceId: $spaceId,
            platform: $platform,
            botInstanceId: $botInstanceId,
            externalConversationId: $externalConversationId,
            externalThreadId: $externalThreadId,
            chatId: $chatId,
            chatType: $chatType,
            topicId: $topicId,
        );
        if (preg_match('/\A[a-zA-Z0-9_]{5,64}\z/D', $botUsername) !== 1) {
            throw new InvalidArgumentException('Space workflow bot username is invalid.');
        }

        foreach ($pendingUpdates as $index => $pendingUpdate) {
            if (!$pendingUpdate instanceof QueuedSpaceUpdate) {
                throw new InvalidArgumentException(
                    sprintf('Pending Space update %d must be a queued update envelope.', $index),
                );
            }
        }

        foreach (
            [
                $processedCount,
                $agentRun,
                $pipelinePendingSince,
                $droppedUpdateCount,
                $ingestionFailureCount,
                $runtimeSnapshotFailureCount,
                $pendingBatchMessageCount,
                $notificationFailureCount,
                $lastSpontaneousReplyAt,
                $humanUpdatesSinceSpontaneousReply,
            ] as $counter
        ) {
            if ($counter < 0) {
                throw new InvalidArgumentException('Workflow counters cannot be negative.');
            }
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
            throw new InvalidArgumentException('Pending Telegram actor IDs must be a list.');
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

        if ($pendingBatchMessageCount === 0) {
            if (
                $pendingBatchId !== null
                || $pendingTopicId !== null
                || $pendingCommandInvocation !== null
                || $pendingRuntimeSnapshot !== null
            ) {
                throw new InvalidArgumentException(
                    'Batch identity, topic route, and runtime snapshot must reset between batches.',
                );
            }
            if ($pendingActorUserIds !== [] || !$pendingActorIdentityComplete) {
                throw new InvalidArgumentException(
                    'Telegram actor identity state must reset between agent batches.',
                );
            }
        } else {
            if ($pendingBatchId === null || trim($pendingBatchId) === '') {
                throw new InvalidArgumentException('A pending agent batch needs a stable batch ID.');
            }
            if ($pendingActorIdentityComplete && $pendingActorUserIds === []) {
                throw new InvalidArgumentException(
                    'A complete pending actor identity set cannot be empty.',
                );
            }
        }

        if ($pendingCommandInvocation !== null && $pendingBatchMessageCount !== 1) {
            throw new InvalidArgumentException(
                'A Space command invocation must be the only message in its pending batch.',
            );
        }

        if (
            $pendingRuntimeSnapshot !== null
            && $pendingRuntimeSnapshot->spaceId !== $spaceId
        ) {
            throw new InvalidArgumentException(
                'A pending runtime snapshot cannot belong to another Space.',
            );
        }
    }

    public static function start(
        SpaceIdentity $identity,
        string $botUsername,
        bool $paused = false,
    ): self {
        return new self(
            spaceId: $identity->spaceId,
            platform: $identity->platform,
            botInstanceId: $identity->botInstanceId,
            externalConversationId: $identity->externalConversationId,
            externalThreadId: $identity->externalThreadId,
            chatId: $identity->chatId,
            chatType: $identity->chatType,
            topicId: $identity->topicId,
            botUsername: $botUsername,
            paused: $paused,
        );
    }

    public function identity(): SpaceIdentity
    {
        return new SpaceIdentity(
            spaceId: $this->spaceId,
            platform: $this->platform,
            botInstanceId: $this->botInstanceId,
            externalConversationId: $this->externalConversationId,
            externalThreadId: $this->externalThreadId,
            chatId: $this->chatId,
            chatType: $this->chatType,
            topicId: $this->topicId,
        );
    }
}
