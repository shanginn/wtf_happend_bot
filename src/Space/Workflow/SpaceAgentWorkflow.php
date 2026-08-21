<?php

declare(strict_types=1);

namespace Bot\Space\Workflow;

use Bot\Activity\TelegramActivity;
use Bot\Llm\Tools\Telegram\TelegramApiCallExecutor;
use Bot\Space\Attention\SpaceResponseDecision;
use Bot\Space\Attention\SpaceResponseDecisionActivityInterface;
use Bot\Space\Attention\SpaceResponseDecisionInput;
use Bot\Space\Command\SpaceCommandActivityInterface;
use Bot\Space\Command\SpaceCommandExecutionInput;
use Bot\Space\Runtime\SpaceCommandBinding;
use Bot\Space\Runtime\SpacePrompt;
use Bot\Space\Runtime\SpaceRuntimeSnapshot;
use Bot\Space\Runtime\SpaceRuntimeSnapshotLoaderActivityInterface;
use Bot\Space\Runtime\SpaceRuntimeSnapshotRequest;
use Bot\Space\Tools\SpaceMemoryBatchEvidence;
use Bot\Telegram\TelegramTopicRouting;
use Bot\Telegram\Update;
use Carbon\CarbonInterval;
use InvalidArgumentException;
use LogicException;
use Phenogram\Bindings\Types\Interfaces\UserInterface;
use PiPHP\Temporal\Activity\DurableAgentActivitiesInterface;
use PiPHP\Temporal\DTO\AgentMessage;
use PiPHP\Temporal\DTO\AgentWorkflowInput;
use PiPHP\Temporal\DTO\AgentWorkflowResult;
use PiPHP\Temporal\DTO\ToolActivityInput;
use PiPHP\Temporal\DTO\ToolActivityResult;
use PiPHP\Temporal\Enum\AgentWorkflowStatus;
use PiPHP\Temporal\Enum\ToolTerminationPolicy;
use PiPHP\Temporal\Serialization\HistoryPayloadGuard;
use PiPHP\Temporal\Workflow\DurableAgentWorkflowInterface;
use Temporal\Activity\ActivityOptions;
use Temporal\Api\Enums\V1\RetryState;
use Temporal\Common\RetryOptions;
use Temporal\DataConverter\Type;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\Failure\ChildWorkflowFailure;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Temporal\Workflow\ChildWorkflowOptions;
use Temporal\Workflow\ContinueAsNewOptions;
use Temporal\Workflow\ReturnType;
use Temporal\Workflow\WorkflowInfo;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;
use Throwable;

#[WorkflowInterface]
final class SpaceAgentWorkflow
{
    public const string WORKFLOW_TYPE      = 'SpaceAgentWorkflowV1';
    public const string PAUSE_SIGNAL_NAME  = 'pause';
    public const string RESUME_SIGNAL_NAME = 'resume';

    private const int PIPELINE_BATCH_WINDOW_SECONDS = 5;
    private const int MAX_UPDATES_BEFORE_CONTINUE   = 30;
    private const int MAX_HISTORY_EVENTS            = 300;
    private const int MAX_HISTORY_BYTES             = 500_000;
    private const int MAX_TELEGRAM_TEXT_LENGTH      = 4096;
    private const int WORKFLOW_TASK_TIMEOUT_SECONDS = 60;
    private const int SPONTANEOUS_COOLDOWN_SECONDS  = 120;
    private const int SPONTANEOUS_MIN_HUMAN_UPDATES = 3;

    private ActivityProxy|TelegramActivity $telegramActivity;
    private ActivityProxy|SpaceRuntimeSnapshotLoaderActivityInterface $runtimeSnapshotActivity;
    private ActivityProxy|SpaceCommandActivityInterface $commandActivity;
    private ActivityProxy|SpaceResponseDecisionActivityInterface $responseDecisionActivity;
    private ActivityProxy|DurableAgentActivitiesInterface $agentActivities;
    private SpaceMessageQueue $updatesQueue;
    private SpaceAgentWorkflowInput $input;

    /** @var list<array<string, mixed>> */
    private array $messages = [];

    private int $processedCount                               = 0;
    private int $processedSinceContinueAsNew                  = 0;
    private int $agentRun                                     = 0;
    private int $pipelinePendingSince                         = 0;
    private int $ingestionFailureCount                        = 0;
    private int $runtimeSnapshotFailureCount                  = 0;
    private int $droppedUpdateCount                           = 0;
    private int $pendingBatchMessageCount                     = 0;
    private int $notificationFailureCount                     = 0;
    private int $lastSpontaneousReplyAt                       = 0;
    private int $humanUpdatesSinceSpontaneousReply            = 0;
    private bool $paused                                      = false;
    private bool $ingestionRetryPending                       = false;
    private bool $callbackPending                             = false;
    private bool $pendingActorIdentityComplete                = true;
    private ?string $lastNotificationFailure                  = null;
    private ?string $pendingBatchId                           = null;
    private ?int $pendingTopicId                              = null;
    private ?SpaceCommandInvocation $pendingCommandInvocation = null;
    private ?SpaceRuntimeSnapshot $pendingRuntimeSnapshot     = null;
    private ?string $pendingTerminalText                      = null;
    private ?string $pendingTerminalScopeId                   = null;

    /** @var array<int, true> */
    private array $seenUpdateIds = [];

    /** @var array<int, true> */
    private array $pendingActorUserIds = [];

    public function __construct()
    {
        $this->telegramActivity = TelegramActivity::getDefinition();
        /** @var SpaceRuntimeSnapshotLoaderActivityInterface $runtimeSnapshotActivity */
        $runtimeSnapshotActivity = Workflow::newActivityStub(
            SpaceRuntimeSnapshotLoaderActivityInterface::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(CarbonInterval::minute())
                ->withRetryOptions(RetryOptions::new()->withMaximumAttempts(3)),
        );
        $this->runtimeSnapshotActivity = $runtimeSnapshotActivity;
        /** @var SpaceCommandActivityInterface $commandActivity */
        $commandActivity = Workflow::newActivityStub(
            SpaceCommandActivityInterface::class,
            ActivityOptions::new()
                ->withScheduleToCloseTimeout(CarbonInterval::minutes(10))
                ->withStartToCloseTimeout(CarbonInterval::minutes(5))
                ->withRetryOptions(
                    RetryOptions::new()
                        ->withInitialInterval(1)
                        ->withMaximumInterval(30)
                        ->withMaximumAttempts(3),
                ),
        );
        $this->commandActivity = $commandActivity;
        /** @var SpaceResponseDecisionActivityInterface $responseDecisionActivity */
        $responseDecisionActivity = Workflow::newActivityStub(
            SpaceResponseDecisionActivityInterface::class,
            ActivityOptions::new()
                ->withScheduleToCloseTimeout(CarbonInterval::minutes(3))
                ->withStartToCloseTimeout(CarbonInterval::minutes(2))
                ->withRetryOptions(
                    RetryOptions::new()
                        ->withInitialInterval(1)
                        ->withMaximumInterval(15)
                        ->withMaximumAttempts(2),
                ),
        );
        $this->responseDecisionActivity = $responseDecisionActivity;
        $this->agentActivities          = Workflow::newActivityStub(
            DurableAgentActivitiesInterface::class,
            ActivityOptions::new()
                ->withScheduleToCloseTimeout(CarbonInterval::minutes(10))
                ->withStartToCloseTimeout(CarbonInterval::minutes(5))
                ->withRetryOptions(
                    RetryOptions::new()
                        ->withInitialInterval(1)
                        ->withMaximumInterval(30)
                        ->withMaximumAttempts(3),
                ),
        );
        $this->updatesQueue = new SpaceMessageQueue();
    }

    #[WorkflowMethod(name: self::WORKFLOW_TYPE)]
    #[ReturnType(Type::TYPE_STRING)]
    public function create(SpaceAgentWorkflowInput $input): mixed
    {
        $this->input                             = $input;
        $this->messages                          = $input->messages;
        $this->processedCount                    = $input->processedCount;
        $this->agentRun                          = $input->agentRun;
        $this->pipelinePendingSince              = $input->pipelinePendingSince;
        $this->paused                            = $input->paused;
        $this->callbackPending                   = $input->callbackPending;
        $this->droppedUpdateCount                = $input->droppedUpdateCount;
        $this->lastNotificationFailure           = $input->lastNotificationFailure;
        $this->ingestionFailureCount             = $input->ingestionFailureCount;
        $this->runtimeSnapshotFailureCount       = $input->runtimeSnapshotFailureCount;
        $this->ingestionRetryPending             = $input->ingestionRetryPending;
        $this->pendingBatchMessageCount          = $input->pendingBatchMessageCount;
        $this->pendingBatchId                    = $input->pendingBatchId;
        $this->pendingTopicId                    = $input->pendingTopicId;
        $this->pendingCommandInvocation          = $input->pendingCommandInvocation;
        $this->pendingActorUserIds               = array_fill_keys($input->pendingActorUserIds, true);
        $this->pendingActorIdentityComplete      = $input->pendingActorIdentityComplete;
        $this->pendingRuntimeSnapshot            = $input->pendingRuntimeSnapshot;
        $this->pendingTerminalText               = $input->pendingTerminalText;
        $this->pendingTerminalScopeId            = $input->pendingTerminalScopeId;
        $this->notificationFailureCount          = $input->notificationFailureCount;
        $this->lastSpontaneousReplyAt            = $input->lastSpontaneousReplyAt;
        $this->humanUpdatesSinceSpontaneousReply = $input->humanUpdatesSinceSpontaneousReply;

        foreach ($input->pendingUpdates as $pendingUpdate) {
            $this->updatesQueue->push($pendingUpdate);
        }

        while (true) {
            if ($this->shouldContinueAsNewAfterFailedAttempt()) {
                return $this->continueAsNew();
            }

            if ($this->pendingTerminalText !== null) {
                if ($this->retryPendingTerminalNotification()) {
                    $this->finishPipeline();
                }

                continue;
            }

            if ($this->paused) {
                if ($this->shouldContinueAsNew(allowPendingPipeline: true)) {
                    return $this->continueAsNew();
                }

                if ($this->updatesQueue->has()) {
                    $this->ingestQueuedUpdates();

                    continue;
                }

                Workflow::await(fn (): bool => !$this->paused || $this->updatesQueue->has());

                continue;
            }

            if ($this->shouldRunAgentImmediately()) {
                if ($this->runAgent()) {
                    $this->finishPipeline();
                }

                continue;
            }

            if (
                $this->pipelinePendingSince > 0
                && $this->processedSinceContinueAsNew >= self::MAX_UPDATES_BEFORE_CONTINUE
            ) {
                if ($this->runAgent()) {
                    $this->finishPipeline();
                }

                continue;
            }

            if ($this->shouldContinueAsNew()) {
                return $this->continueAsNew();
            }

            if ($this->updatesQueue->has()) {
                $this->ingestQueuedUpdates();

                continue;
            }

            if ($this->pipelinePendingSince > 0) {
                $remaining = $this->secondsUntilPipeline();
                if ($remaining <= 0) {
                    if ($this->runAgent()) {
                        $this->finishPipeline();
                    }

                    continue;
                }

                Workflow::awaitWithTimeout(
                    $remaining,
                    fn (): bool => $this->updatesQueue->has() || $this->paused,
                );

                continue;
            }

            Workflow::await(fn (): bool => $this->updatesQueue->has() || $this->paused);
        }
    }

    #[Workflow\SignalMethod]
    public function addUpdate(Update $update): void
    {
        $this->enqueueUpdate($update);
    }

    #[Workflow\SignalMethod(name: self::PAUSE_SIGNAL_NAME)]
    public function pause(): void
    {
        $this->paused = true;
    }

    #[Workflow\SignalMethod(name: self::RESUME_SIGNAL_NAME)]
    public function resume(): void
    {
        $this->paused = false;
    }

    #[Workflow\QueryMethod]
    public function getUpdatesQueue(): array
    {
        return $this->updatesQueue->all();
    }

    #[Workflow\QueryMethod]
    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    #[Workflow\QueryMethod]
    #[ReturnType(Type::TYPE_BOOL)]
    public function isPaused(): bool
    {
        return $this->paused;
    }

    #[Workflow\QueryMethod]
    public function getMemory(): array
    {
        return $this->messages;
    }

    #[Workflow\QueryMethod]
    public function getDroppedUpdateCount(): int
    {
        return $this->droppedUpdateCount;
    }

    #[Workflow\QueryMethod]
    public function getLastNotificationFailure(): ?string
    {
        return $this->lastNotificationFailure;
    }

    private static function executionChainId(WorkflowInfo $info): string
    {
        $runId = $info->firstExecutionRunId;
        if (!is_string($runId) || $runId === '') {
            $runId = $info->execution->getRunID();
        }
        if (!is_string($runId) || $runId === '') {
            throw new LogicException('Temporal workflow execution chain ID is unavailable.');
        }

        return $runId;
    }

    /**
     * Uses PiPH's concrete AgentWorkflowInput validation for every candidate,
     * so the selected message suffix observes the complete serialized payload
     * budget, including tools and metadata.
     *
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed>       $metadata
     * @param string                     $agentId
     * @param string                     $model
     * @param int                        $pendingBatchMessageCount
     */
    private static function boundedAgentInput(
        string $agentId,
        string $model,
        array $messages,
        array $tools,
        array $metadata,
        int $pendingBatchMessageCount,
    ): AgentWorkflowInput {
        if ($pendingBatchMessageCount < 1) {
            throw new LogicException('Pending agent batch message state is inconsistent.');
        }

        $lastFailure = null;
        foreach (self::messageSuffixCandidates($messages, $pendingBatchMessageCount) as $candidate) {
            try {
                $candidate = self::collapsePendingBatchMessages(
                    $candidate,
                    $pendingBatchMessageCount,
                );

                return new AgentWorkflowInput(
                    agentId: $agentId,
                    model: $model,
                    messages: $candidate,
                    tools: $tools,
                    maxTurns: SpaceAgentRuntime::MAX_TURNS,
                    continueAsNewEvery: SpaceAgentRuntime::CONTINUE_AS_NEW_EVERY_TURNS,
                    // PiPH's count limit is soft; the byte guard remains hard.
                    // Never let the child trim an accepted current batch merely
                    // because a parent continuation reset its update counter.
                    maxRetainedMessages: max(
                        SpaceAgentRuntime::MAX_RETAINED_MESSAGES,
                        count($candidate),
                    ),
                    metadata: $metadata,
                    toolTerminationPolicy: ToolTerminationPolicy::Any,
                );
            } catch (InvalidArgumentException $failure) {
                $lastFailure = $failure;
            }
        }

        throw self::agentInputTooLarge(
            $lastFailure ?? new InvalidArgumentException(
                'PiPH rejected the mandatory agent workflow input.',
            ),
        );
    }

    /**
     * PiPH trims only at user-turn boundaries. Treating each Telegram update as
     * a separate user turn would let later model/tool output silently evict an
     * early update from the still-pending batch. Collapse the complete batch
     * into one user turn so PiPH either retains every update or rejects the
     * indivisible turn when it cannot fit the hard payload budget.
     *
     * @param list<array<string, mixed>> $messages
     * @param int                        $pendingBatchMessageCount
     *
     * @return list<array<string, mixed>>
     */
    private static function collapsePendingBatchMessages(
        array $messages,
        int $pendingBatchMessageCount,
    ): array {
        $messages = array_values($messages);
        if (
            $pendingBatchMessageCount < 1
            || $pendingBatchMessageCount > count($messages)
        ) {
            throw new LogicException('Pending agent batch message state is inconsistent.');
        }

        $batchStart = count($messages) - $pendingBatchMessageCount;
        $batch      = array_slice($messages, $batchStart);
        $content    = [[
            'type' => 'text',
            'text' => sprintf(
                'Telegram batch containing %d ordered update%s:',
                $pendingBatchMessageCount,
                $pendingBatchMessageCount === 1 ? '' : 's',
            ),
        ]];
        $participantReferences = [];

        foreach ($batch as $index => $message) {
            if (($message['role'] ?? null) !== 'user') {
                throw new LogicException(
                    'Every pending Telegram batch message must be a user message.',
                );
            }

            $blocks = $message['content'] ?? null;
            if (!is_array($blocks) || !array_is_list($blocks)) {
                throw new LogicException(
                    'Every pending Telegram batch message must contain a content block list.',
                );
            }

            $reference = $message['metadata']['telegramParticipant']
                ?? $message['name']
                ?? null;
            if (is_string($reference) && $reference !== '') {
                $participantReferences[] = $reference;
            } else {
                $reference = null;
            }

            $content[] = [
                'type' => 'text',
                'text' => sprintf(
                    'Telegram update %d%s:',
                    $index + 1,
                    $reference === null ? '' : " from {$reference}",
                ),
            ];
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    throw new LogicException(
                        'Every pending Telegram batch content block must be an object.',
                    );
                }

                $content[] = $block;
            }
        }

        $composite = new AgentMessage(
            role: 'user',
            content: $content,
            metadata: [
                'telegramBatch'             => true,
                'telegramBatchMessageCount' => $pendingBatchMessageCount,
                'telegramParticipants'      => $participantReferences,
            ],
        );

        return [
            ...array_slice($messages, 0, $batchStart),
            $composite->toArray(),
        ];
    }

    /**
     * Uses the last Telegram message timestamp in the ordered pending batch.
     * The value is carried by the durable message envelope, so a backlog or a
     * parent continue-as-new cannot move relative history searches to host time.
     *
     * @param list<array<string, mixed>> $messages
     * @param int                        $pendingBatchMessageCount
     */
    private static function pendingBatchHistoryReferenceTimestamp(
        array $messages,
        int $pendingBatchMessageCount,
    ): ?int {
        $messages = array_values($messages);
        if (
            $pendingBatchMessageCount < 1
            || $pendingBatchMessageCount > count($messages)
        ) {
            throw new LogicException('Pending agent batch message state is inconsistent.');
        }

        $batchStart = count($messages) - $pendingBatchMessageCount;
        for ($index = count($messages) - 1; $index >= $batchStart; --$index) {
            $timestamp = $messages[$index]['metadata']['telegramMessageTimestamp'] ?? null;
            if (is_int($timestamp) && $timestamp > 0) {
                return $timestamp;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param int                        $pendingBatchMessageCount
     *
     * @return list<list<array<string, mixed>>>
     */
    private static function messageSuffixCandidates(
        array $messages,
        int $pendingBatchMessageCount,
    ): array {
        $messages = array_values($messages);
        $system   = ($messages[0]['role'] ?? null) === 'system'
            ? $messages[0]
            : null;
        $body = $system === null ? $messages : array_slice($messages, 1);
        if (
            $pendingBatchMessageCount < 0
            || $pendingBatchMessageCount > count($body)
        ) {
            throw new LogicException('Pending agent batch message state is inconsistent.');
        }

        $priorCount      = count($body) - $pendingBatchMessageCount;
        $prior           = array_slice($body, 0, $priorCount);
        $currentBatch    = array_slice($body, $priorCount);
        $candidateStarts = [0];
        foreach ($prior as $index => $message) {
            if ($index > 0 && ($message['role'] ?? null) === 'user') {
                $candidateStarts[] = $index;
            }
        }
        if ($prior !== []) {
            $candidateStarts[] = count($prior);
        }

        $candidates = [];
        foreach ($candidateStarts as $start) {
            $candidate = [
                ...array_slice($prior, $start),
                ...$currentBatch,
            ];
            if ($system !== null) {
                array_unshift($candidate, $system);
            }
            $candidates[] = $candidate;
        }

        return $candidates;
    }

    private static function agentInputTooLarge(
        InvalidArgumentException $failure,
    ): ApplicationFailure {
        return new ApplicationFailure(
            message: sprintf(
                'The current Telegram batch and mandatory agent context exceed '
                . 'PiPH\'s %d-byte workflow input budget.',
                HistoryPayloadGuard::MAX_ENCODED_BYTES,
            ),
            type: 'agent-workflow-input-too-large',
            nonRetryable: true,
            previous: $failure,
        );
    }

    /**
     * @param list<QueuedSpaceUpdate> $updates
     * @param ?int                    $pendingTopicId
     * @param bool                    $hasPendingBatch
     *
     * @return array{list<QueuedSpaceUpdate>, list<QueuedSpaceUpdate>}
     */
    private static function nextIngestionBatch(
        array $updates,
        ?int $pendingTopicId,
        bool $hasPendingBatch,
    ): array {
        foreach ($updates as $index => $queuedUpdate) {
            if (!$queuedUpdate instanceof QueuedSpaceUpdate) {
                throw new LogicException(
                    sprintf('Queued Space update %d has an invalid envelope.', $index),
                );
            }
        }

        usort(
            $updates,
            static fn (QueuedSpaceUpdate $left, QueuedSpaceUpdate $right): int => $left->update->updateId <=> $right->update->updateId
                ?: strcmp($left->ingestionId, $right->ingestionId),
        );

        $selectedTopicId        = $pendingTopicId;
        $hasSelectedTopic       = $hasPendingBatch;
        $hasSelectedAgentUpdate = $hasPendingBatch;
        foreach ($updates as $index => $queuedUpdate) {
            if ($queuedUpdate->appendToAgent) {
                $command = SpaceCommandInvocation::fromUpdate($queuedUpdate->update);
                if ($command !== null && $hasSelectedAgentUpdate) {
                    return [
                        array_slice($updates, 0, $index),
                        array_slice($updates, $index),
                    ];
                }

                $topicId = TelegramTopicRouting::topicId($queuedUpdate->update->effectiveMessage);
                if (!$hasSelectedTopic) {
                    $selectedTopicId  = $topicId;
                    $hasSelectedTopic = true;
                } elseif ($topicId !== $selectedTopicId) {
                    return [
                        array_slice($updates, 0, $index),
                        array_slice($updates, $index),
                    ];
                }

                $hasSelectedAgentUpdate = true;
            }

            if (
                $queuedUpdate->appendToAgent
                && $queuedUpdate->update->callbackQuery !== null
            ) {
                return [
                    array_slice($updates, 0, $index + 1),
                    array_slice($updates, $index + 1),
                ];
            }

            if (
                $queuedUpdate->appendToAgent
                && SpaceCommandInvocation::fromUpdate($queuedUpdate->update) !== null
            ) {
                return [
                    array_slice($updates, 0, $index + 1),
                    array_slice($updates, $index + 1),
                ];
            }
        }

        return [$updates, []];
    }

    private static function toolResultSummary(ToolActivityResult $result): string
    {
        $encoded = json_encode(
            $result->content,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return is_string($encoded)
            ? "Telegram notification tool returned an error: {$encoded}"
            : 'Telegram notification tool returned an unencodable error.';
    }

    private static function truncateTelegramText(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_TELEGRAM_TEXT_LENGTH) {
            return $text;
        }

        $suffix = "\n… [truncated]";

        return mb_substr(
            $text,
            0,
            self::MAX_TELEGRAM_TEXT_LENGTH - mb_strlen($suffix),
        ) . $suffix;
    }

    private static function notificationRetryDelaySeconds(int $failureCount): int
    {
        if ($failureCount < 1) {
            throw new LogicException('Notification failure count must be positive.');
        }

        return min(60, 2 ** min(5, $failureCount - 1));
    }

    private static function parentNotificationIdempotencyKey(
        string $workflowId,
        string $executionChainId,
        int $agentRun,
        string $text,
    ): string {
        return 'parent-notification:' . hash('sha256', implode(':', [
            $workflowId,
            $executionChainId,
            (string) $agentRun,
            $text,
        ]));
    }

    private static function completedResultText(AgentWorkflowResult $result): string
    {
        if ($result->status !== AgentWorkflowStatus::Completed) {
            return '';
        }

        $finalText = trim($result->finalText ?? '');
        if ($finalText !== '') {
            return $finalText;
        }

        for ($index = count($result->snapshot->messages) - 1; $index >= 0; --$index) {
            $message = $result->snapshot->messages[$index];
            if (($message['role'] ?? null) !== 'assistant') {
                continue;
            }

            $textBlocks = [];
            $content    = $message['content'] ?? null;
            if (!is_array($content)) {
                return '';
            }
            foreach ($content as $block) {
                if (
                    is_array($block)
                    && ($block['type'] ?? null) === 'text'
                    && is_string($block['text'] ?? null)
                ) {
                    $textBlocks[] = $block['text'];
                }
            }

            return trim(implode("\n", $textBlocks));
        }

        return '';
    }

    /**
     * The pending batch becomes a complete PiPH turn after the child returns.
     * Persist the whole turn across parent continue-as-new, not merely its
     * original composite user message.
     *
     * @param list<array<string, mixed>> $messages
     */
    private static function pendingBatchTurnMessageCount(array $messages): int
    {
        $body = ($messages[0]['role'] ?? null) === 'system'
            ? array_slice($messages, 1)
            : $messages;

        for ($index = count($body) - 1; $index >= 0; --$index) {
            if (
                ($body[$index]['role'] ?? null) === 'user'
                && ($body[$index]['metadata']['telegramBatch'] ?? false) === true
            ) {
                return count($body) - $index;
            }
        }

        throw new LogicException('PiPH result lost the pending Telegram user turn.');
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $metadata
     * @param string                     $model
     * @param SpaceCommandBinding        $binding
     * @param SpaceCommandInvocation     $invocation
     * @param int                        $pendingBatchMessageCount
     * @param string                     $idempotencyKey
     */
    private static function boundedCommandInput(
        string $model,
        SpaceCommandBinding $binding,
        SpaceCommandInvocation $invocation,
        array $messages,
        array $metadata,
        int $pendingBatchMessageCount,
        string $idempotencyKey,
    ): SpaceCommandExecutionInput {
        $lastFailure = null;
        foreach (self::messageSuffixCandidates($messages, $pendingBatchMessageCount) as $candidate) {
            try {
                $candidate = self::collapsePendingBatchMessages(
                    $candidate,
                    $pendingBatchMessageCount,
                );

                return new SpaceCommandExecutionInput(
                    model: $model,
                    binding: $binding,
                    argumentText: $invocation->argumentText,
                    messages: $candidate,
                    metadata: $metadata,
                    idempotencyKey: $idempotencyKey,
                );
            } catch (InvalidArgumentException $failure) {
                $lastFailure = $failure;
            }
        }

        throw new ApplicationFailure(
            message: sprintf(
                'The current Telegram command and mandatory context exceed the %d-byte workflow input budget.',
                HistoryPayloadGuard::MAX_ENCODED_BYTES,
            ),
            type: 'space-command-input-too-large',
            nonRetryable: true,
            previous: $lastFailure,
        );
    }

    /**
     * Keep enough nearby context to understand who is being addressed while
     * bounding the cheap routing pass independently of the main agent input.
     *
     * @param list<array<string, mixed>> $messages
     * @param int                        $pendingBatchMessageCount
     *
     * @return list<array<string, mixed>>
     */
    private static function decisionMessages(array $messages, int $pendingBatchMessageCount): array
    {
        $candidates = self::messageSuffixCandidates($messages, $pendingBatchMessageCount);
        $candidate  = $candidates[max(0, count($candidates) - 2)] ?? null;
        if (!is_array($candidate)) {
            throw new LogicException('Space attention could not build a bounded message suffix.');
        }

        return self::collapsePendingBatchMessages($candidate, $pendingBatchMessageCount);
    }

    /** @param list<array<string, mixed>> $messages */
    private static function pendingBatchAddressesBot(
        array $messages,
        int $pendingBatchMessageCount,
    ): bool {
        $start = count($messages) - $pendingBatchMessageCount;
        if ($start < 0) {
            throw new LogicException('Pending agent batch message state is inconsistent.');
        }
        foreach (array_slice($messages, $start) as $message) {
            if (($message['metadata']['telegramBotAddressed'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function ingestQueuedUpdates(): void
    {
        [$updates, $remainingUpdates] = self::nextIngestionBatch(
            $this->updatesQueue->flush(),
            $this->pendingTopicId,
            $this->pendingBatchMessageCount > 0,
        );
        if ($remainingUpdates !== []) {
            $this->updatesQueue->prepend($remainingUpdates);
        }

        foreach ($updates as $index => $queuedUpdate) {
            if ($this->processedSinceContinueAsNew >= self::MAX_UPDATES_BEFORE_CONTINUE) {
                $this->updatesQueue->prepend(array_slice($updates, $index));

                return;
            }

            $update = $queuedUpdate->update;
            if (isset($this->seenUpdateIds[$update->updateId])) {
                ++$this->processedSinceContinueAsNew;

                continue;
            }

            try {
                $owned = $this->telegramActivity->saveUpdates(
                    $update,
                    $this->input->chatId,
                    $queuedUpdate->ingestionId,
                );
            } catch (ActivityFailure $failure) {
                if ($this->dropNonRetryableUpdate($failure, $update)) {
                    continue;
                }

                $this->retryIngestion($updates, $index);

                return;
            }

            $this->ingestionRetryPending            = false;
            $this->seenUpdateIds[$update->updateId] = true;
            ++$this->processedSinceContinueAsNew;
            if (!$owned) {
                continue;
            }

            $commandInvocation = $queuedUpdate->appendToAgent
                ? SpaceCommandInvocation::fromUpdate($update)
                : null;
            if (
                $commandInvocation !== null
                && !$commandInvocation->isForBot($this->input->botUsername)
            ) {
                // The raw update is persisted, but foreign bot commands need
                // neither an agent view nor any local pipeline state.
                ++$this->processedCount;

                continue;
            }

            try {
                $view = $this->telegramActivity->updateToView($update);
            } catch (ActivityFailure $failure) {
                if ($this->dropNonRetryableUpdate($failure, $update, alreadyCounted: true)) {
                    ++$this->processedCount;

                    continue;
                }

                unset($this->seenUpdateIds[$update->updateId]);
                --$this->processedSinceContinueAsNew;
                $this->retryIngestion($updates, $index);

                return;
            }

            ++$this->processedCount;
            if (!$queuedUpdate->appendToAgent) {
                continue;
            }

            if ($this->pipelinePendingSince === 0) {
                $this->pipelinePendingSince = Workflow::now()->getTimestamp();
                $this->pendingBatchId       = Workflow::uuid4()->toString();
                $this->pendingTopicId       = TelegramTopicRouting::topicId(
                    $update->effectiveMessage,
                );
            }

            if ($update->callbackQuery !== null) {
                $this->callbackPending = true;
            }
            if ($commandInvocation !== null) {
                if (
                    $this->pendingCommandInvocation !== null
                    || $this->pendingBatchMessageCount !== 0
                ) {
                    throw new LogicException(
                        'A Telegram slash command must be isolated in its own Space batch.',
                    );
                }
                $this->pendingCommandInvocation = $commandInvocation;
            }
            $botAddressed     = $this->updateAddressesBot($update);
            $this->messages[] = SpaceTelegramAgentMessageMapper::map($view, $botAddressed)->toArray();
            ++$this->pendingBatchMessageCount;
            $this->trackPendingActor($update);
            if ($update->effectiveSender instanceof UserInterface
                && !$update->effectiveSender->isBot
            ) {
                ++$this->humanUpdatesSinceSpontaneousReply;
            }
        }

        $this->ingestionFailureCount = 0;
        $this->ingestionRetryPending = false;
    }

    private function runAgent(): bool
    {
        $runtimeSnapshot = $this->runtimeSnapshotForPendingBatch();
        if ($runtimeSnapshot === null) {
            return false;
        }

        ++$this->agentRun;
        $parentInfo       = Workflow::getInfo();
        $executionChainId = self::executionChainId($parentInfo);
        $terminalScopeId  = hash('sha256', implode(':', [
            $parentInfo->execution->getID(),
            $executionChainId,
            'batch',
            (string) $this->agentRun,
        ]));
        $workflowId = sprintf(
            '%s:%s:agent:%d',
            $parentInfo->execution->getID(),
            $executionChainId,
            $this->agentRun,
        );

        $commandInvocation = $this->pendingCommandInvocation;
        if ($commandInvocation !== null) {
            return $this->runCommand(
                runtimeSnapshot: $runtimeSnapshot,
                binding: $commandInvocation->isForBot($this->input->botUsername)
                    ? $runtimeSnapshot->command($commandInvocation->name)
                    : null,
                invocation: $commandInvocation,
                terminalScopeId: $terminalScopeId,
                idempotencyKey: sprintf(
                    'space-command:%s:%s:%s',
                    $terminalScopeId,
                    substr(hash('sha256', $runtimeSnapshot->releaseDigest), 0, 16),
                    $commandInvocation->name,
                ),
            );
        }

        $historyReferenceTimestamp = self::pendingBatchHistoryReferenceTimestamp(
            $this->messages,
            $this->pendingBatchMessageCount,
        );
        $directRequired = $this->input->chatType === 'private'
            || self::pendingBatchAddressesBot(
                $this->messages,
                $this->pendingBatchMessageCount,
            );
        $decision = $this->responseDecision(
            runtimeSnapshot: $runtimeSnapshot,
            terminalScopeId: $terminalScopeId,
            directRequired: $directRequired,
            historyReferenceTimestamp: $historyReferenceTimestamp,
        );
        if (!$decision->runsAgent()) {
            $this->messages = self::collapsePendingBatchMessages(
                $this->messages,
                $this->pendingBatchMessageCount,
            );
            $this->pendingBatchMessageCount = 1;

            return true;
        }

        $selectedSkills = [];
        foreach ($decision->selectedSkillNames as $name) {
            $skill = $runtimeSnapshot->skill($name);
            if ($skill === null) {
                throw new LogicException('Space attention selected a skill outside the pinned snapshot.');
            }
            $selectedSkills[] = $skill;
        }

        try {
            $this->replaceSystemMessage(SpacePrompt::withSelectedSkills(
                $runtimeSnapshot->systemPrompt,
                $selectedSkills,
            ));
        } catch (InvalidArgumentException $failure) {
            throw self::agentInputTooLarge($failure);
        }

        $agentInput = self::boundedAgentInput(
            agentId: 'space-' . $this->input->spaceId,
            model: $runtimeSnapshot->model,
            messages: $this->messages,
            tools: $runtimeSnapshot->tools,
            metadata: [
                ...$this->input->identity()->metadata(),
                ...$runtimeSnapshot->metadata(),
                'externalThreadId' => $this->pendingTopicId === null
                    ? null
                    : (string) $this->pendingTopicId,
                'topicId'               => $this->pendingTopicId,
                'batchId'               => $this->pendingBatchId,
                'actorUserIds'          => $this->pendingActorIds(),
                'actorIdentityComplete' => $this->pendingActorIdentityComplete,
                'memoryEvidence'        => SpaceMemoryBatchEvidence::fromPendingMessages(
                    $this->messages,
                    $this->pendingBatchMessageCount,
                ),
                'historyReferenceTimestamp' => $historyReferenceTimestamp,
                'spaceAttentionMode'        => $decision->mode,
                'selectedSpaceSkills'       => $decision->selectedSkillNames,
                'terminalScopeId'           => $terminalScopeId,
                'parentWorkflowId'          => $parentInfo->execution->getID(),
                'parentWorkflowType'        => self::WORKFLOW_TYPE,
            ],
            pendingBatchMessageCount: $this->pendingBatchMessageCount,
        );
        $this->messages = $agentInput->messages;
        // The accepted Telegram batch is now one indivisible PiPH user turn.
        $this->pendingBatchMessageCount = 1;

        /** @var DurableAgentWorkflowInterface $agent */
        $agent = Workflow::newChildWorkflowStub(
            DurableAgentWorkflowInterface::class,
            ChildWorkflowOptions::new()
                ->withWorkflowId($workflowId)
                ->withTaskQueue($parentInfo->taskQueue)
                ->withWorkflowTaskTimeout(CarbonInterval::minute()),
        );

        try {
            $result = $agent->run($agentInput);
        } catch (ChildWorkflowFailure) {
            return $this->notifyProcessingFailure($terminalScopeId);
        }

        if (!$result instanceof AgentWorkflowResult) {
            throw new LogicException('PiPH durable agent returned an invalid result.');
        }

        $this->messages                 = $result->snapshot->messages;
        $this->pendingBatchMessageCount = self::pendingBatchTurnMessageCount($this->messages);
        if ($this->hasAmbiguousToolAttempt($workflowId)) {
            return $this->queueTerminalNotification(
                'Не удалось подтвердить результат внешнего действия. '
                . 'Я не повторял его, чтобы избежать дубля. '
                . 'Проверьте чат и попробуйте ещё раз при необходимости.',
                $terminalScopeId,
            );
        }

        if ($this->hasConfirmedTerminalAction($workflowId)) {
            if ($decision->isSpontaneous() && $this->hasConfirmedVisibleTerminalAction($workflowId)) {
                $this->markSpontaneousReply($historyReferenceTimestamp);
            }

            return true;
        }

        if ($this->terminalToolCallIds() !== []) {
            return $this->notifyProcessingFailure($terminalScopeId);
        }

        if ($result->status === AgentWorkflowStatus::Completed) {
            $fallback = self::completedResultText($result);
            if ($fallback !== '') {
                if ($decision->isSpontaneous()) {
                    $this->markSpontaneousReply($historyReferenceTimestamp);
                }

                return $this->queueTerminalNotification($fallback, $terminalScopeId);
            }
        }

        return $this->notifyProcessingFailure($terminalScopeId);
    }

    private function runCommand(
        SpaceRuntimeSnapshot $runtimeSnapshot,
        ?SpaceCommandBinding $binding,
        SpaceCommandInvocation $invocation,
        string $terminalScopeId,
        string $idempotencyKey,
    ): bool {
        if (!$invocation->isForBot($this->input->botUsername)) {
            throw new LogicException('A foreign-target command reached Space execution.');
        }

        if ($binding === null) {
            if ($this->shouldSilentlyIgnoreUnboundCommand($invocation)) {
                $this->discardPendingCommandFromAgentHistory();

                return true;
            }

            return $this->queueTerminalNotification(
                sprintf('Команда /%s не зарегистрирована или выключена.', $invocation->name),
                $terminalScopeId,
            );
        }

        $commandInput = self::boundedCommandInput(
            model: $runtimeSnapshot->model,
            binding: $binding,
            invocation: $invocation,
            messages: $this->messages,
            metadata: [
                ...$this->input->identity()->metadata(),
                ...$runtimeSnapshot->metadata(),
                'topicId'               => $this->pendingTopicId,
                'batchId'               => $this->pendingBatchId,
                'actorUserIds'          => $this->pendingActorIds(),
                'actorIdentityComplete' => $this->pendingActorIdentityComplete,
            ],
            pendingBatchMessageCount: $this->pendingBatchMessageCount,
            idempotencyKey: $idempotencyKey,
        );
        $this->messages                 = $commandInput->messages;
        $this->pendingBatchMessageCount = 1;

        try {
            $text = $this->commandActivity->execute($commandInput);
        } catch (ActivityFailure) {
            return $this->notifyProcessingFailure($terminalScopeId);
        }

        return $this->queueTerminalNotification($text, $terminalScopeId);
    }

    private function shouldSilentlyIgnoreUnboundCommand(
        SpaceCommandInvocation $invocation,
    ): bool {
        return $this->input->chatType !== 'private'
            && $invocation->targetUsername === null;
    }

    private function discardPendingCommandFromAgentHistory(): void
    {
        if (
            $this->pendingBatchMessageCount !== 1
            || count($this->messages) < $this->pendingBatchMessageCount
        ) {
            throw new LogicException(
                'An unbound Telegram command must be the only pending agent message.',
            );
        }

        array_pop($this->messages);
        $this->pendingBatchMessageCount = 0;
    }

    /**
     * Load once and pin the complete immutable release/memory/capability view
     * until this Telegram batch has reached its one terminal outcome.
     */
    private function runtimeSnapshotForPendingBatch(): ?SpaceRuntimeSnapshot
    {
        if ($this->pendingRuntimeSnapshot !== null) {
            return $this->pendingRuntimeSnapshot;
        }
        $batchId = $this->pendingBatchId;
        if ($batchId === null || $this->pendingBatchMessageCount < 1) {
            throw new LogicException('Cannot load a runtime snapshot without a pending batch.');
        }

        try {
            $snapshot = $this->runtimeSnapshotActivity->loadSnapshot(
                new SpaceRuntimeSnapshotRequest($this->input->spaceId, $batchId),
            );
        } catch (ActivityFailure $failure) {
            ++$this->runtimeSnapshotFailureCount;
            Workflow::getLogger()->error(
                'Unable to load the immutable Space runtime snapshot; the batch remains pending.',
                [
                    'spaceId' => $this->input->spaceId,
                    'batchId' => $batchId,
                    'failure' => $failure->getMessage(),
                ],
            );
            Workflow::timer(CarbonInterval::seconds(min(
                60,
                2 ** min(5, $this->runtimeSnapshotFailureCount - 1),
            )));

            return null;
        }

        if (!$snapshot instanceof SpaceRuntimeSnapshot) {
            throw new LogicException('Space runtime activity returned an invalid snapshot.');
        }
        if ($snapshot->spaceId !== $this->input->spaceId) {
            throw new LogicException('Space runtime activity returned a snapshot for another Space.');
        }

        $this->runtimeSnapshotFailureCount = 0;
        $this->pendingRuntimeSnapshot      = $snapshot;

        return $snapshot;
    }

    private function responseDecision(
        SpaceRuntimeSnapshot $runtimeSnapshot,
        string $terminalScopeId,
        bool $directRequired,
        ?int $historyReferenceTimestamp,
    ): SpaceResponseDecision {
        $spontaneousAllowed = $this->spontaneousAllowed($historyReferenceTimestamp);
        $input              = new SpaceResponseDecisionInput(
            model: $runtimeSnapshot->model,
            messages: self::decisionMessages(
                $this->messages,
                $this->pendingBatchMessageCount,
            ),
            skills: $runtimeSnapshot->skills,
            directRequired: $directRequired,
            spontaneousAllowed: $spontaneousAllowed,
            idempotencyKey: sprintf(
                'space-attention:%s:%s',
                $terminalScopeId,
                substr(hash('sha256', $runtimeSnapshot->releaseDigest), 0, 16),
            ),
        );

        try {
            $decision = $this->responseDecisionActivity->decide($input);
        } catch (ActivityFailure $failure) {
            Workflow::getLogger()->warning(
                'Space attention gate failed; applying the bounded host fallback.',
                [
                    'spaceId'        => $this->input->spaceId,
                    'batchId'        => $this->pendingBatchId,
                    'directRequired' => $directRequired,
                    'failure'        => $failure->getMessage(),
                ],
            );

            return new SpaceResponseDecision(
                $directRequired
                    ? SpaceResponseDecision::MODE_BASE
                    : SpaceResponseDecision::MODE_SILENT,
            );
        }
        if (!$decision instanceof SpaceResponseDecision) {
            throw new LogicException('Space attention activity returned an invalid decision.');
        }

        return $decision;
    }

    private function spontaneousAllowed(?int $referenceTimestamp): bool
    {
        if ($this->lastSpontaneousReplyAt === 0) {
            return true;
        }
        if ($this->humanUpdatesSinceSpontaneousReply < self::SPONTANEOUS_MIN_HUMAN_UPDATES) {
            return false;
        }
        if ($referenceTimestamp === null) {
            return false;
        }

        return $referenceTimestamp - $this->lastSpontaneousReplyAt
            >= self::SPONTANEOUS_COOLDOWN_SECONDS;
    }

    private function markSpontaneousReply(?int $referenceTimestamp): void
    {
        $this->lastSpontaneousReplyAt = max(
            1,
            $referenceTimestamp ?? Workflow::now()->getTimestamp(),
        );
        $this->humanUpdatesSinceSpontaneousReply = 0;
    }

    private function notifyProcessingFailure(string $terminalScopeId): bool
    {
        return $this->queueTerminalNotification(
            'Не удалось завершить обработку сообщения. Попробуйте ещё раз.',
            $terminalScopeId,
        );
    }

    private function queueTerminalNotification(string $text, string $terminalScopeId): bool
    {
        $this->stageTerminalNotification($text, $terminalScopeId);

        return $this->retryPendingTerminalNotification();
    }

    private function stageTerminalNotification(string $text, string $terminalScopeId): void
    {
        $text            = self::truncateTelegramText(trim($text));
        $terminalScopeId = trim($terminalScopeId);
        if ($text === '' || $terminalScopeId === '') {
            throw new LogicException('A pending terminal notification must be non-empty.');
        }

        if ($this->pendingTerminalText !== null) {
            if (
                $this->pendingTerminalText !== $text
                || $this->pendingTerminalScopeId !== $terminalScopeId
            ) {
                throw new LogicException(
                    'A different terminal notification is already pending for this batch.',
                );
            }

            return;
        }

        $this->pendingTerminalText      = $text;
        $this->pendingTerminalScopeId   = $terminalScopeId;
        $this->notificationFailureCount = 0;
        $this->lastNotificationFailure  = null;
    }

    private function retryPendingTerminalNotification(): bool
    {
        $text            = $this->pendingTerminalText;
        $terminalScopeId = $this->pendingTerminalScopeId;
        if ($text === null || $terminalScopeId === null) {
            throw new LogicException('No terminal notification is pending.');
        }

        if ($this->notifyChat($text, $terminalScopeId)) {
            $this->confirmPendingTerminalNotification();
            $this->rememberCommandReply($text);

            return true;
        }

        Workflow::timer(CarbonInterval::seconds(
            $this->markPendingTerminalNotificationFailed(),
        ));

        return false;
    }

    private function markPendingTerminalNotificationFailed(): int
    {
        ++$this->notificationFailureCount;

        return self::notificationRetryDelaySeconds($this->notificationFailureCount);
    }

    private function confirmPendingTerminalNotification(): void
    {
        $this->pendingTerminalText      = null;
        $this->pendingTerminalScopeId   = null;
        $this->notificationFailureCount = 0;
        $this->lastNotificationFailure  = null;
    }

    private function rememberCommandReply(string $text): void
    {
        $invocation = $this->pendingCommandInvocation;
        if ($invocation === null) {
            return;
        }

        $this->messages[] = (new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => $text]],
            metadata: [
                'spaceCommand'  => $invocation->name,
                'hostDelivered' => true,
            ],
        ))->toArray();
    }

    private function notifyChat(string $text, string $terminalScopeId): bool
    {
        $text             = self::truncateTelegramText($text);
        $info             = Workflow::getInfo();
        $workflowId       = $info->execution->getID();
        $executionChainId = self::executionChainId($info);
        $idempotencyKey   = self::parentNotificationIdempotencyKey(
            $workflowId,
            $executionChainId,
            $this->agentRun,
            $text,
        );

        try {
            $result = $this->agentActivities->executeTool(new ToolActivityInput(
                callId: "parent-notification-{$this->agentRun}",
                name: 'telegram_api_call',
                arguments: [
                    'method'     => 'sendMessage',
                    'parameters' => ['text' => $text],
                ],
                idempotencyKey: $idempotencyKey,
                metadata: [
                    'chatId'             => $this->input->chatId,
                    'topicId'            => $this->pendingTopicId,
                    'terminalScopeId'    => $terminalScopeId,
                    'parentWorkflowId'   => $workflowId,
                    'parentWorkflowType' => self::WORKFLOW_TYPE,
                    'source'             => 'parent-notification',
                ],
            ));
        } catch (ActivityFailure $failure) {
            return $this->recordNotificationFailure($failure->getMessage());
        }

        if (!$result instanceof ToolActivityResult) {
            return $this->recordNotificationFailure('PiPH returned an invalid parent notification result.');
        }

        if (($result->metadata['terminalActionSuppressed'] ?? false) === true) {
            if (($result->metadata['terminalActionState'] ?? null) === 'claimed') {
                return $this->recordNotificationFailure(
                    'The batch terminal action has an unknown outcome; the parent notification '
                    . 'was suppressed to avoid a duplicate Telegram action.',
                );
            }

            Workflow::getLogger()->info(
                'Parent notification was suppressed because this batch already claimed its terminal action.',
                ['terminalScopeId' => $terminalScopeId],
            );
            $this->lastNotificationFailure = null;

            return true;
        }

        if ($result->isError || !$result->terminate) {
            return $this->recordNotificationFailure(self::toolResultSummary($result));
        }

        $this->lastNotificationFailure = null;

        return true;
    }

    private function hasAmbiguousToolAttempt(string $childWorkflowId): bool
    {
        foreach ($this->messages as $message) {
            $metadata = $message['metadata'] ?? null;
            if (
                is_array($metadata)
                && ($metadata['workflowId'] ?? null) === $childWorkflowId
                && ($metadata['ambiguousPriorAttempt'] ?? false) === true
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasConfirmedTerminalAction(string $childWorkflowId): bool
    {
        $terminalToolCalls = $this->terminalToolCallIds();

        foreach ($this->messages as $message) {
            $toolCallId = $message['toolCallId'] ?? null;
            $metadata   = $message['metadata'] ?? null;
            if (
                ($message['role'] ?? null) !== 'toolResult'
                || !is_string($toolCallId)
                || !isset($terminalToolCalls[$toolCallId])
                || !is_array($metadata)
                || ($metadata['workflowId'] ?? null) !== $childWorkflowId
            ) {
                continue;
            }

            if (($message['isError'] ?? false) === false) {
                return true;
            }

            if (
                ($metadata['terminalActionSuppressed'] ?? false) === true
                && ($metadata['terminalActionState'] ?? null) === 'completed'
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasConfirmedVisibleTerminalAction(string $childWorkflowId): bool
    {
        $terminalToolCalls = $this->visibleTerminalToolCallIds();
        foreach ($this->messages as $message) {
            $toolCallId = $message['toolCallId'] ?? null;
            $metadata   = $message['metadata'] ?? null;
            if (
                ($message['role'] ?? null) === 'toolResult'
                && is_string($toolCallId)
                && isset($terminalToolCalls[$toolCallId])
                && is_array($metadata)
                && ($metadata['workflowId'] ?? null) === $childWorkflowId
                && ($message['isError'] ?? false) === false
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private function visibleTerminalToolCallIds(): array
    {
        $calls = [];
        foreach ($this->messages as $message) {
            if (($message['role'] ?? null) !== 'assistant' || !is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $block) {
                if (!is_array($block)
                    || ($block['type'] ?? null) !== 'toolCall'
                    || !is_string($block['id'] ?? null)
                    || ($block['name'] ?? null) !== 'telegram_api_call'
                ) {
                    continue;
                }
                $method = $block['arguments']['method'] ?? null;
                if (is_string($method) && TelegramApiCallExecutor::isTerminalMethod($method)) {
                    $calls[$block['id']] = true;
                }
            }
        }

        return $calls;
    }

    /**
     * @return array<string, true>
     */
    private function terminalToolCallIds(): array
    {
        $terminalToolCalls = [];
        foreach ($this->messages as $message) {
            if (($message['role'] ?? null) !== 'assistant') {
                continue;
            }

            $content = $message['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $block) {
                if (
                    !is_array($block)
                    || ($block['type'] ?? null) !== 'toolCall'
                    || !is_string($block['id'] ?? null)
                ) {
                    continue;
                }

                $name = $block['name'] ?? null;
                if ($name === 'stay_silent') {
                    $terminalToolCalls[$block['id']] = true;

                    continue;
                }

                $method = $block['arguments']['method'] ?? null;
                if (
                    $name === 'telegram_api_call'
                    && is_string($method)
                    && TelegramApiCallExecutor::isTerminalMethod($method)
                ) {
                    $terminalToolCalls[$block['id']] = true;
                }
            }
        }

        return $terminalToolCalls;
    }

    private function replaceSystemMessage(string $prompt): void
    {
        $system = AgentMessage::text('system', $prompt)->toArray();
        if (($this->messages[0]['role'] ?? null) === 'system') {
            $this->messages[0] = $system;

            return;
        }

        array_unshift($this->messages, $system);
    }

    private function secondsUntilPipeline(): int
    {
        return max(
            0,
            ($this->pipelinePendingSince + self::PIPELINE_BATCH_WINDOW_SECONDS)
            - Workflow::now()->getTimestamp(),
        );
    }

    private function shouldRunAgentImmediately(): bool
    {
        return $this->pipelinePendingSince > 0
            && (
                $this->callbackPending
                || $this->pendingCommandInvocation !== null
                || $this->queuedUpdateTargetsAnotherTopic()
            );
    }

    private function queuedUpdateTargetsAnotherTopic(): bool
    {
        if ($this->pendingBatchMessageCount < 1) {
            return false;
        }

        foreach ($this->updatesQueue->all() as $queuedUpdate) {
            if (!$queuedUpdate instanceof QueuedSpaceUpdate || !$queuedUpdate->appendToAgent) {
                continue;
            }

            if (TelegramTopicRouting::topicId($queuedUpdate->update->effectiveMessage)
                !== $this->pendingTopicId
            ) {
                return true;
            }
        }

        return false;
    }

    private function shouldContinueAsNewAfterFailedAttempt(): bool
    {
        if (
            !$this->ingestionRetryPending
            && $this->runtimeSnapshotFailureCount === 0
            && $this->notificationFailureCount === 0
            && $this->pendingTerminalText === null
            && $this->pendingTerminalScopeId === null
        ) {
            return false;
        }

        return $this->continuationSuggested();
    }

    private function shouldContinueAsNew(bool $allowPendingPipeline = false): bool
    {
        if ($this->ingestionRetryPending) {
            return false;
        }

        if (!$allowPendingPipeline && $this->pipelinePendingSince > 0) {
            return false;
        }

        return $this->continuationSuggested();
    }

    private function continuationSuggested(): bool
    {
        $info = Workflow::getInfo();

        return $this->processedSinceContinueAsNew >= self::MAX_UPDATES_BEFORE_CONTINUE
            || $info->historyLength >= self::MAX_HISTORY_EVENTS
            || $info->historySize >= self::MAX_HISTORY_BYTES
            || $info->shouldContinueAsNew;
    }

    private function finishPipeline(): void
    {
        if (
            $this->pendingTerminalText !== null
            || $this->pendingTerminalScopeId !== null
            || $this->notificationFailureCount !== 0
        ) {
            throw new LogicException(
                'A batch cannot finish while its terminal notification is unconfirmed.',
            );
        }

        $this->pipelinePendingSince         = 0;
        $this->callbackPending              = false;
        $this->pendingBatchMessageCount     = 0;
        $this->pendingBatchId               = null;
        $this->pendingTopicId               = null;
        $this->pendingCommandInvocation     = null;
        $this->pendingActorUserIds          = [];
        $this->pendingActorIdentityComplete = true;
        $this->pendingRuntimeSnapshot       = null;
        $this->runtimeSnapshotFailureCount  = 0;
    }

    /**
     * @param list<QueuedSpaceUpdate> $updates
     * @param int                     $failedIndex
     */
    private function retryIngestion(array $updates, int $failedIndex): void
    {
        $this->updatesQueue->prepend(array_slice($updates, $failedIndex));
        $this->ingestionRetryPending = true;
        ++$this->ingestionFailureCount;
        Workflow::timer(CarbonInterval::seconds(min(
            60,
            2 ** min(5, $this->ingestionFailureCount - 1),
        )));
    }

    private function dropNonRetryableUpdate(
        ActivityFailure $failure,
        Update $update,
        bool $alreadyCounted = false,
    ): bool {
        if ($failure->getRetryState() !== RetryState::RETRY_STATE_NON_RETRYABLE_FAILURE) {
            return false;
        }

        $this->seenUpdateIds[$update->updateId] = true;
        if (!$alreadyCounted) {
            ++$this->processedSinceContinueAsNew;
        }
        ++$this->droppedUpdateCount;
        $this->ingestionRetryPending = false;
        Workflow::getLogger()->error(
            'Dropped a non-retryable Telegram update instead of blocking the chat workflow.',
            [
                'chatId'   => $this->input->chatId,
                'topicId'  => TelegramTopicRouting::topicId($update->effectiveMessage),
                'updateId' => $update->updateId,
                'failure'  => $failure->getMessage(),
            ],
        );

        return true;
    }

    private function enqueueUpdate(Update $update): void
    {
        if (!$this->updateBelongsToSpace($update)) {
            return;
        }

        $this->updatesQueue->push(new QueuedSpaceUpdate(
            update: $update,
            appendToAgent: !$this->paused,
            ingestionId: Workflow::uuid4()->toString(),
        ));
    }

    /**
     * Signal arguments are untrusted workflow input. Keep this check pure so
     * replay always makes the same routing decision from the pinned identity.
     *
     * @param Update $update
     */
    private function updateBelongsToSpace(Update $update): bool
    {
        $chat = $update->effectiveChat;
        if ($chat === null
            || $this->input->platform !== 'telegram'
            || $chat->id !== $this->input->chatId
            || (string) $chat->id !== $this->input->externalConversationId
        ) {
            return false;
        }

        return true;
    }

    /**
     * A conservative host signal for guaranteed replies. The semantic gate
     * still recognizes natural-language addressing; this method supplies the
     * deterministic cases that must survive a router/provider failure.
     *
     * @param Update $update
     */
    private function updateAddressesBot(Update $update): bool
    {
        if ($this->input->chatType === 'private' || $update->callbackQuery !== null) {
            return true;
        }
        $message = $update->effectiveMessage;
        if ($message === null) {
            return false;
        }
        $replyAuthor = $message->replyToMessage?->from;
        if (
            $replyAuthor instanceof UserInterface
            && $replyAuthor->isBot
            && is_string($replyAuthor->username)
            && strcasecmp($replyAuthor->username, $this->input->botUsername) === 0
        ) {
            return true;
        }
        $text = trim((string) ($message->text ?? $message->caption ?? ''));
        if ($text === '') {
            return false;
        }
        $username = preg_quote($this->input->botUsername, '/');

        return preg_match('/@' . $username . '(?![a-zA-Z0-9_])/iu', $text) === 1
            || preg_match('/\A\s*(?:бот|bot)\b/iu', $text) === 1;
    }

    private function trackPendingActor(Update $update): void
    {
        $sender = $update->effectiveSender;
        if (!$sender instanceof UserInterface || $sender->id <= 0 || $sender->isBot) {
            $this->pendingActorIdentityComplete = false;

            return;
        }

        $this->pendingActorUserIds[$sender->id] = true;
    }

    /**
     * @return list<int>
     */
    private function pendingActorIds(): array
    {
        $actorIds = array_keys($this->pendingActorUserIds);
        sort($actorIds, \SORT_NUMERIC);

        return $actorIds;
    }

    private function recordNotificationFailure(string $failure): bool
    {
        $failure                       = trim($failure);
        $this->lastNotificationFailure = mb_substr(
            $failure === '' ? 'Unknown Telegram notification failure.' : $failure,
            0,
            500,
        );
        Workflow::getLogger()->error(
            'Unable to deliver a parent workflow notification.',
            [
                'chatId'  => $this->input->chatId,
                'topicId' => $this->pendingTopicId,
                'failure' => $this->lastNotificationFailure,
            ],
        );

        return false;
    }

    private function boundedContinuationInput(): SpaceAgentWorkflowInput
    {
        $converter   = new SpaceAgentWorkflowInputDataConverter();
        $lastFailure = null;
        foreach (
            self::messageSuffixCandidates(
                $this->messages,
                $this->pendingBatchMessageCount,
            ) as $candidateMessages
        ) {
            try {
                $candidate    = $this->continuationInput($candidateMessages);
                $encodedBytes = $converter->encodedBytes($candidate);
            } catch (Throwable $failure) {
                $lastFailure = $failure;

                continue;
            }

            if ($encodedBytes <= HistoryPayloadGuard::MAX_ENCODED_BYTES) {
                return $candidate;
            }
            $lastFailure = new InvalidArgumentException(sprintf(
                'Space agent workflow continuation requires %d encoded bytes.',
                $encodedBytes,
            ));
        }

        throw new ApplicationFailure(
            message: sprintf(
                'The current Telegram batch and mandatory parent workflow state exceed '
                . 'the %d-byte continuation budget.',
                HistoryPayloadGuard::MAX_ENCODED_BYTES,
            ),
            type: 'space-agent-workflow-input-too-large',
            nonRetryable: true,
            previous: $lastFailure,
        );
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function continuationInput(array $messages): SpaceAgentWorkflowInput
    {
        return new SpaceAgentWorkflowInput(
            spaceId: $this->input->spaceId,
            platform: $this->input->platform,
            botInstanceId: $this->input->botInstanceId,
            externalConversationId: $this->input->externalConversationId,
            externalThreadId: $this->input->externalThreadId,
            chatId: $this->input->chatId,
            chatType: $this->input->chatType,
            topicId: $this->input->topicId,
            botUsername: $this->input->botUsername,
            messages: $messages,
            processedCount: $this->processedCount,
            agentRun: $this->agentRun,
            pipelinePendingSince: $this->pipelinePendingSince,
            pendingUpdates: $this->updatesQueue->all(),
            paused: $this->paused,
            callbackPending: $this->callbackPending,
            droppedUpdateCount: $this->droppedUpdateCount,
            lastNotificationFailure: $this->lastNotificationFailure,
            ingestionFailureCount: $this->ingestionFailureCount,
            runtimeSnapshotFailureCount: $this->runtimeSnapshotFailureCount,
            ingestionRetryPending: $this->ingestionRetryPending,
            pendingBatchMessageCount: $this->pendingBatchMessageCount,
            pendingBatchId: $this->pendingBatchId,
            pendingTopicId: $this->pendingTopicId,
            pendingCommandInvocation: $this->pendingCommandInvocation,
            pendingActorUserIds: $this->pendingActorIds(),
            pendingActorIdentityComplete: $this->pendingActorIdentityComplete,
            pendingRuntimeSnapshot: $this->pendingRuntimeSnapshot,
            pendingTerminalText: $this->pendingTerminalText,
            pendingTerminalScopeId: $this->pendingTerminalScopeId,
            notificationFailureCount: $this->notificationFailureCount,
            lastSpontaneousReplyAt: $this->lastSpontaneousReplyAt,
            humanUpdatesSinceSpontaneousReply: $this->humanUpdatesSinceSpontaneousReply,
        );
    }

    private function continueAsNew(): mixed
    {
        $input          = $this->boundedContinuationInput();
        $this->messages = $input->messages;

        return Workflow::continueAsNew(
            self::WORKFLOW_TYPE,
            [$input],
            ContinueAsNewOptions::new()->withWorkflowTaskTimeout(
                CarbonInterval::seconds(self::WORKFLOW_TASK_TIMEOUT_SECONDS),
            ),
        );
    }
}
