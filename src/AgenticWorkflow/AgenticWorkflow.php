<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Activity\TelegramActivity;
use Bot\Llm\Tools\Telegram\TelegramApiCallExecutor;
use Bot\Telegram\Update;
use Bot\Temporal\AgenticWorkflowInputDataConverter;
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
final class AgenticWorkflow
{
    public const string WORKFLOW_TYPE      = 'AgenticWorkflow';
    public const string PAUSE_SIGNAL_NAME  = 'pause';
    public const string RESUME_SIGNAL_NAME = 'resume';

    private const int PIPELINE_BATCH_WINDOW_SECONDS = 5;
    private const int MAX_UPDATES_BEFORE_CONTINUE   = 100;
    private const int MAX_TELEGRAM_TEXT_LENGTH      = 4096;
    private const int WORKFLOW_TASK_TIMEOUT_SECONDS = 60;

    private ActivityProxy|TelegramActivity $telegramActivity;
    private ActivityProxy|AgentContextActivity $contextActivity;
    private ActivityProxy|DurableAgentActivitiesInterface $agentActivities;
    private MessageQueue $updatesQueue;
    private AgenticWorkflowInput $input;

    /** @var list<array<string, mixed>> */
    private array $messages = [];

    private int $processedCount                = 0;
    private int $processedSinceContinueAsNew   = 0;
    private int $agentRun                      = 0;
    private int $pipelinePendingSince          = 0;
    private int $ingestionFailureCount         = 0;
    private int $contextFailureCount           = 0;
    private int $droppedUpdateCount            = 0;
    private int $pendingBatchMessageCount      = 0;
    private int $notificationFailureCount      = 0;
    private bool $paused                       = false;
    private bool $ingestionRetryPending        = false;
    private bool $callbackPending              = false;
    private bool $pendingActorIdentityComplete = true;
    private ?string $lastNotificationFailure   = null;
    private ?string $pendingTerminalText       = null;
    private ?string $pendingTerminalScopeId    = null;

    /** @var array<int, true> */
    private array $seenUpdateIds = [];

    /** @var array<int, true> */
    private array $pendingActorUserIds = [];

    public function __construct()
    {
        $this->telegramActivity = TelegramActivity::getDefinition();
        $this->contextActivity  = Workflow::newActivityStub(
            AgentContextActivity::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(CarbonInterval::minute())
                ->withRetryOptions(RetryOptions::new()->withMaximumAttempts(3)),
        );
        $this->agentActivities = Workflow::newActivityStub(
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
        $this->updatesQueue = new MessageQueue();
    }

    #[WorkflowMethod(name: self::WORKFLOW_TYPE)]
    #[ReturnType(Type::TYPE_STRING)]
    public function create(AgenticWorkflowInput $input): mixed
    {
        $this->input                        = $input;
        $this->messages                     = $input->messages;
        $this->processedCount               = $input->processedCount;
        $this->agentRun                     = $input->agentRun;
        $this->pipelinePendingSince         = $input->pipelinePendingSince;
        $this->paused                       = $input->paused;
        $this->callbackPending              = $input->callbackPending;
        $this->droppedUpdateCount           = $input->droppedUpdateCount;
        $this->lastNotificationFailure      = $input->lastNotificationFailure;
        $this->ingestionFailureCount        = $input->ingestionFailureCount;
        $this->contextFailureCount          = $input->contextFailureCount;
        $this->ingestionRetryPending        = $input->ingestionRetryPending;
        $this->pendingBatchMessageCount     = $input->pendingBatchMessageCount;
        $this->pendingActorUserIds          = array_fill_keys($input->pendingActorUserIds, true);
        $this->pendingActorIdentityComplete = $input->pendingActorIdentityComplete;
        $this->pendingTerminalText          = $input->pendingTerminalText;
        $this->pendingTerminalScopeId       = $input->pendingTerminalScopeId;
        $this->notificationFailureCount     = $input->notificationFailureCount;

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
                    maxTurns: AgentRuntime::MAX_TURNS,
                    continueAsNewEvery: AgentRuntime::CONTINUE_AS_NEW_EVERY_TURNS,
                    // PiPH's count limit is soft; the byte guard remains hard.
                    // Never let the child trim an accepted current batch merely
                    // because a parent continuation reset its update counter.
                    maxRetainedMessages: max(
                        AgentRuntime::MAX_RETAINED_MESSAGES,
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
     * @param list<QueuedTelegramUpdate> $updates
     *
     * @return array{list<QueuedTelegramUpdate>, list<QueuedTelegramUpdate>}
     */
    private static function nextIngestionBatch(array $updates): array
    {
        usort(
            $updates,
            static fn (QueuedTelegramUpdate $left, QueuedTelegramUpdate $right): int => $left->update->updateId <=> $right->update->updateId
                ?: strcmp($left->ingestionId, $right->ingestionId),
        );

        foreach ($updates as $index => $queuedUpdate) {
            if (
                $queuedUpdate->appendToAgent
                && $queuedUpdate->update->callbackQuery !== null
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

    private function ingestQueuedUpdates(): void
    {
        [$updates, $remainingUpdates] = self::nextIngestionBatch(
            $this->updatesQueue->flush(),
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
            }

            if ($update->callbackQuery !== null) {
                $this->callbackPending = true;
            }
            $this->messages[] = TelegramAgentMessageMapper::map($view)->toArray();
            ++$this->pendingBatchMessageCount;
            $this->trackPendingActor($update);
        }

        $this->ingestionFailureCount = 0;
        $this->ingestionRetryPending = false;
    }

    private function runAgent(): bool
    {
        try {
            $runtimeInstructions = $this->contextActivity->runtimeInstructions($this->input->chatId);
        } catch (ActivityFailure $failure) {
            ++$this->contextFailureCount;
            Workflow::getLogger()->error(
                'Unable to load chat runtime instructions; the agent batch remains pending.',
                [
                    'chatId'  => $this->input->chatId,
                    'topicId' => $this->input->topicId,
                    'failure' => $failure->getMessage(),
                ],
            );
            Workflow::timer(CarbonInterval::seconds(min(
                60,
                2 ** min(5, $this->contextFailureCount - 1),
            )));

            return false;
        }
        $this->contextFailureCount = 0;

        try {
            $this->replaceSystemMessage(AgentPrompt::build($runtimeInstructions));
        } catch (InvalidArgumentException $failure) {
            throw self::agentInputTooLarge($failure);
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
        $agentInput = self::boundedAgentInput(
            agentId: sprintf('telegram-chat-%d', $this->input->chatId),
            model: $this->input->model,
            messages: $this->messages,
            tools: $this->input->tools,
            metadata: [
                'chatId'                    => $this->input->chatId,
                'chatType'                  => $this->input->chatType,
                'actorUserIds'              => $this->pendingActorIds(),
                'actorIdentityComplete'     => $this->pendingActorIdentityComplete,
                'topicId'                   => $this->input->topicId,
                'historyReferenceTimestamp' => self::pendingBatchHistoryReferenceTimestamp(
                    $this->messages,
                    $this->pendingBatchMessageCount,
                ),
                'terminalScopeId'    => $terminalScopeId,
                'parentWorkflowId'   => $parentInfo->execution->getID(),
                'parentWorkflowType' => self::WORKFLOW_TYPE,
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
            return true;
        }

        if ($this->terminalToolCallIds() !== []) {
            return $this->notifyProcessingFailure($terminalScopeId);
        }

        if ($result->status === AgentWorkflowStatus::Completed) {
            $fallback = self::completedResultText($result);
            if ($fallback !== '') {
                return $this->queueTerminalNotification($fallback, $terminalScopeId);
            }
        }

        return $this->notifyProcessingFailure($terminalScopeId);
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
                    'topicId'            => $this->input->topicId,
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
        return $this->pipelinePendingSince > 0 && $this->callbackPending;
    }

    private function shouldContinueAsNewAfterFailedAttempt(): bool
    {
        if (
            !$this->ingestionRetryPending
            && $this->contextFailureCount === 0
            && $this->notificationFailureCount === 0
            && $this->pendingTerminalText === null
            && $this->pendingTerminalScopeId === null
        ) {
            return false;
        }

        return $this->processedSinceContinueAsNew >= self::MAX_UPDATES_BEFORE_CONTINUE
            || Workflow::getInfo()->shouldContinueAsNew;
    }

    private function shouldContinueAsNew(bool $allowPendingPipeline = false): bool
    {
        if ($this->ingestionRetryPending) {
            return false;
        }

        if (!$allowPendingPipeline && $this->pipelinePendingSince > 0) {
            return false;
        }

        return $this->processedSinceContinueAsNew >= self::MAX_UPDATES_BEFORE_CONTINUE
            || Workflow::getInfo()->shouldContinueAsNew;
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
        $this->pendingActorUserIds          = [];
        $this->pendingActorIdentityComplete = true;
    }

    /**
     * @param list<QueuedTelegramUpdate> $updates
     * @param int                        $failedIndex
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
                'topicId'  => $this->input->topicId,
                'updateId' => $update->updateId,
                'failure'  => $failure->getMessage(),
            ],
        );

        return true;
    }

    private function enqueueUpdate(Update $update): void
    {
        $this->updatesQueue->push(new QueuedTelegramUpdate(
            update: $update,
            appendToAgent: !$this->paused,
            ingestionId: Workflow::uuid4()->toString(),
        ));
    }

    private function trackPendingActor(Update $update): void
    {
        $sender = $update->effectiveSender;
        if (!$sender instanceof UserInterface || $sender->id <= 0) {
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
                'topicId' => $this->input->topicId,
                'failure' => $this->lastNotificationFailure,
            ],
        );

        return false;
    }

    private function boundedContinuationInput(): AgenticWorkflowInput
    {
        $converter   = new AgenticWorkflowInputDataConverter();
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
                'Agentic workflow continuation requires %d encoded bytes.',
                $encodedBytes,
            ));
        }

        throw new ApplicationFailure(
            message: sprintf(
                'The current Telegram batch and mandatory parent workflow state exceed '
                . 'the %d-byte continuation budget.',
                HistoryPayloadGuard::MAX_ENCODED_BYTES,
            ),
            type: 'agentic-workflow-input-too-large',
            nonRetryable: true,
            previous: $lastFailure,
        );
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function continuationInput(array $messages): AgenticWorkflowInput
    {
        return new AgenticWorkflowInput(
            chatId: $this->input->chatId,
            chatType: $this->input->chatType,
            topicId: $this->input->topicId,
            model: $this->input->model,
            tools: $this->input->tools,
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
            contextFailureCount: $this->contextFailureCount,
            ingestionRetryPending: $this->ingestionRetryPending,
            pendingBatchMessageCount: $this->pendingBatchMessageCount,
            pendingActorUserIds: $this->pendingActorIds(),
            pendingActorIdentityComplete: $this->pendingActorIdentityComplete,
            pendingTerminalText: $this->pendingTerminalText,
            pendingTerminalScopeId: $this->pendingTerminalScopeId,
            notificationFailureCount: $this->notificationFailureCount,
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
