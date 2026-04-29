<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Activity\TelegramActivity;
use Bot\Agent\OpenaiMessageTransformer;
use Bot\Llm\Tools\Decision\RespondDecision;
use Bot\Llm\Tools\Runtime\UpsertRuntimeSkill;
use Bot\Llm\Tools\Runtime\UpsertRuntimeTool;
use Bot\Llm\Tools\Telegram\TelegramApiCall;
use Bot\Llm\Tools\Telegram\TelegramApiCallExecutor;
use Carbon\CarbonInterval;
use Generator;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\Assistant\KnownFunctionCall;
use Shanginn\Openai\ChatCompletion\Message\Assistant\UnknownFunctionCall;
use Shanginn\Openai\ChatCompletion\Message\AssistantMessage;
use Shanginn\Openai\ChatCompletion\Message\ToolMessage;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
use Temporal\Exception\Failure\CanceledFailure;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\DataConverter\Type;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Temporal\Workflow\ReturnType;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
class AgenticWorkflow
{
    public const string WORKFLOW_TYPE = 'AgenticWorkflow';

    private const int COMPACTION_INTERVAL_SECONDS = 86400;
    private const int IDLE_COMPACTION_AFTER_SECONDS = 3600;
    private const int COMPACTION_RETRY_AFTER_SECONDS = 300;
    private const int MAX_COMPACTION_RETRY_AFTER_SECONDS = 3600;
    private const int MAX_COMPACTION_FAILURES_BEFORE_DROP = 5;
    private const int MAX_UPDATES_BEFORE_CONTINUE = 100;
    private const int MAX_DECISION_STEPS = 3;
    private const int MAX_RESPONSE_STEPS = 15;
    private const int PIPELINE_BATCH_WINDOW_SECONDS = 5;
    private const int TYPING_ACTION_REFRESH_INTERVAL_SECONDS = 4;

    private AgenticActivity|ActivityProxy $agenticActivity;
    private TelegramActivity|ActivityProxy $telegramActivity;
    private MessageQueue $updatesQueue;
    private AgenticWorkflowInput $input;
    private int $lastActivityAt = 0;
    private int $lastCompactionAt = 0;
    private int $compactionRetryAfter = 0;
    private int $consecutiveCompactionFailures = 0;
    private int $pipelinePendingSince = 0;
    private int $processedCount = 0;
    private int $processedSinceContinueAsNew = 0;
    private int $typingIndicatorGeneration = 0;

    private WorkingMemory $workingMemory;

    public function __construct()
    {
        $this->agenticActivity = AgenticActivity::getDefinition();
        $this->telegramActivity = TelegramActivity::getDefinition();
        $this->updatesQueue = new MessageQueue();
        $this->workingMemory = new WorkingMemory();
    }

    #[WorkflowMethod(name: self::WORKFLOW_TYPE)]
    #[ReturnType(Type::TYPE_STRING)]
    public function create(AgenticWorkflowInput $input): Generator
    {
        $this->input = $input;
        $this->processedCount = $input->processedCount;
        $this->lastActivityAt = $input->lastActivityAt;
        $this->lastCompactionAt = $input->lastCompactionAt;
        $this->compactionRetryAfter = $input->compactionRetryAfter;
        $this->consecutiveCompactionFailures = $input->consecutiveCompactionFailures;
        $this->pipelinePendingSince = $input->pipelinePendingSince;
        $this->workingMemory = new WorkingMemory(
            memories: $input->workingMemory,
            compactedContext: $input->compactedContext,
        );
        $this->initializeCompactionClock();

        do {
            if ($this->updatesQueue->has()) {
                yield from $this->ingestQueuedUpdates();
            }

            if ($this->shouldCompactNow()) {
                yield from $this->compactWorkingMemory();
                continue;
            }

            if ($this->shouldRunPipelineNow()) {
                yield from $this->runAgentLoop();
                $this->pipelinePendingSince = 0;
                continue;
            }

            if (!$this->hasPendingPipeline() && $this->shouldContinueAsNew()) {
                return yield from $this->continueAsNew();
            }

            yield from $this->waitForUpdatesOrWorkflowDeadline();
        } while (true);
    }

    private function ingestQueuedUpdates(): Generator
    {
        $updates = $this->updatesQueue->flush();
        $now = $this->currentTimestamp();
        $this->lastActivityAt = $now;

        if (!$this->hasPendingPipeline()) {
            $this->pipelinePendingSince = $now;
        }

        foreach ($updates as $update) {
            yield $this->telegramActivity->saveUpdates($update);
        }

        foreach ($updates as $update) {
            $inputMessageView = yield $this->telegramActivity->updateToView($update);
            $userMessageView = OpenaiMessageTransformer::toChatUserMessage($inputMessageView);

            $this->workingMemory->add($userMessageView);
        }

        $this->processedCount += count($updates);
        $this->processedSinceContinueAsNew += count($updates);
    }

    private function runAgentLoop(): Generator
    {
        $decisionMemory = $this->workingMemory->getContext(recentLimit: 10);
        $initialDecisionMemory = $decisionMemory;

        for ($step = 0; $step < self::MAX_DECISION_STEPS; ++$step) {
            $result = yield $this->agenticActivity->memoryComplete(
                memory: $decisionMemory,
                tools: AgenticToolset::DECISION_TOOLS,
                chatId: $this->input->chatId,
            );

            if ($result instanceof ErrorResponse) {
                $errorMessage = $result->message ?? 'Unknown error.';
                yield $this->sendMessage('Произошла ошибка: ' . $errorMessage);

                return;
            }

            $choice = $result->choices[0] ?? null;
            if ($choice === null) {
                return;
            }

            yield $this->agenticActivity->saveResponseMessage(
                chatId: $this->input->chatId,
                topicId: null,
                message: $choice->message,
                rawResponse: $result,
            );

            $assistantMessage = $choice->message;
            $shouldRespond = null;
            $decisionToolCalls = [];
            $decisionToolMessages = [];
            $executedToolCalls = [];
            $executedToolMessages = [];

            foreach ($assistantMessage->toolCalls ?? [] as $toolCall) {
                if ($toolCall instanceof UnknownFunctionCall) {
                    $decisionToolCalls[] = $toolCall;
                    $decisionToolMessages[] = $this->decisionToolUnavailableMessage($toolCall->name, $toolCall->id);
                    continue;
                }

                if (!$toolCall instanceof KnownFunctionCall) {
                    continue;
                }

                if ($toolCall->arguments instanceof RespondDecision) {
                    $shouldRespond = $toolCall->arguments->shouldRespond;
                    continue;
                }

                if (!$this->isExecutableDecisionToolCall($toolCall)) {
                    $decisionToolCalls[] = $toolCall;
                    $decisionToolMessages[] = $this->decisionToolUnavailableMessage($toolCall->tool, $toolCall->id);
                    continue;
                }

                $toolResult = yield $this->executeTool(
                    toolName: $toolCall->tool,
                    arguments: $toolCall->arguments,
                );

                $toolMessage = new ToolMessage(
                    content: $toolResult,
                    toolCallId: $toolCall->id,
                );

                $decisionToolCalls[] = $toolCall;
                $decisionToolMessages[] = $toolMessage;
                $executedToolCalls[] = $toolCall;
                $executedToolMessages[] = $toolMessage;
            }

            if ($decisionToolCalls !== []) {
                $decisionMemory[] = AssistantMessage::withToolCalls($assistantMessage, $decisionToolCalls);

                foreach ($decisionToolMessages as $toolMessage) {
                    $decisionMemory[] = $toolMessage;

                    yield $this->agenticActivity->saveResponseMessage(
                        chatId: $this->input->chatId,
                        topicId: null,
                        message: $toolMessage,
                    );
                }
            }

            $this->rememberDecisionToolResults($assistantMessage, $executedToolCalls, $executedToolMessages);

            if ($shouldRespond !== null) {
                if ($shouldRespond) {
                    yield from $this->respondWithTyping();
                }

                return;
            }

            $decisionMemory[] = $this->invalidDecisionRetryMessage($decisionToolCalls !== []);
        }

        if ($this->shouldFallbackRespond($initialDecisionMemory)) {
            yield from $this->respondWithTyping();
        }
    }

    private function isExecutableDecisionToolCall(KnownFunctionCall $toolCall): bool
    {
        return in_array($toolCall->tool, AgenticToolset::DECISION_TOOLS, true)
            && !$toolCall->arguments instanceof RespondDecision;
    }

    private function decisionToolUnavailableMessage(string $toolName, string $toolCallId): ToolMessage
    {
        return new ToolMessage(
            content: sprintf(
                'Tool "%s" is not available in the decision phase. Do not execute tools here. '
                . 'Finish by calling respond_decision; set shouldRespond=true if the response agent should handle this request.',
                $toolName,
            ),
            toolCallId: $toolCallId,
        );
    }

    private function invalidDecisionRetryMessage(bool $hadToolCalls): UserMessage
    {
        $reason = $hadToolCalls
            ? 'The previous decision step used tool calls but did not call respond_decision.'
            : 'The previous decision step did not call respond_decision.';

        return new UserMessage(
            $reason . ' This completion is invalid. Call respond_decision now. '
            . 'If the latest user message asks for bot functionality or runtime tools, set shouldRespond=true.'
        );
    }

    /**
     * Keep decision-side memory tool effects visible to later decision passes.
     * The internal respond_decision tool is intentionally excluded because it
     * has no tool result and would make the chat-completion history invalid.
     *
     * @param array<KnownFunctionCall> $toolCalls
     * @param array<ToolMessage>       $toolMessages
     */
    private function rememberDecisionToolResults(
        AssistantMessage $assistantMessage,
        array $toolCalls,
        array $toolMessages,
    ): void {
        if ($toolCalls === [] || $toolMessages === []) {
            return;
        }

        $this->workingMemory->add(AssistantMessage::withToolCalls($assistantMessage, $toolCalls));

        foreach ($toolMessages as $toolMessage) {
            $this->workingMemory->add($toolMessage);
        }
    }

    /**
     * Last-resort guard for malformed decision completions. It is deliberately
     * conservative and only responds when the recent Telegram text clearly
     * looks directed at the bot or invokes a command.
     *
     * @param array<object> $messages
     */
    private function shouldFallbackRespond(array $messages): bool
    {
        for ($index = count($messages) - 1; $index >= 0; --$index) {
            $message = $messages[$index] ?? null;
            if (!$message instanceof UserMessage) {
                continue;
            }

            $content = self::stringifyUserContent($message->content);
            $normalized = mb_strtolower($content);

            if (
                preg_match('/(^|\s)\/[a-z0-9_]+/iu', $content) === 1
                || str_contains($normalized, '@wtf_happend_bot')
                || str_contains($normalized, '@wtf_happened_bot')
                || str_contains($normalized, 'бот')
                || str_contains($normalized, 'bot')
            ) {
                return true;
            }
        }

        return false;
    }

    private static function stringifyUserContent(array|string $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        return json_encode($content, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '';
    }

    private function respondWithTyping(): Generator
    {
        yield $this->sendTypingAction();

        $this->startTypingIndicator();

        try {
            yield from $this->respond();
        } finally {
            $this->stopTypingIndicator();
        }
    }

    private function respond(): Generator
    {
        $memorySelection = yield $this->agenticActivity->recollectRelevantMemories(
            chatId: $this->input->chatId,
            history: $this->workingMemory->getContext(),
        );

        if ($memorySelection instanceof ErrorResponse) {
            $errorMessage = $memorySelection->message ?? 'Unknown error.';
            yield $this->sendMessage('Произошла ошибка: ' . $errorMessage);

            return;
        }

        $relevantMemories = 'No relevant memories.';

        $memoryChoice = $memorySelection->choices[0] ?? null;
        $memoryContent = $memoryChoice?->message->content === null
            ? ''
            : trim($memoryChoice->message->content);

        if ($memoryContent !== '') {
            $relevantMemories = $memoryContent;
        }

        $responseMemory = $this->workingMemory->getContext();
        $responseMemory[] = new UserMessage(
            "Relevant participant memories for this reply:\n{$relevantMemories}",
        );
        $responseMemory[] = new UserMessage(
            "Current Telegram API target context:\n"
            . "- current chat_id: {$this->input->chatId}\n"
            . "- When using telegram_api_call for this chat, omit chat_id/chatId and the tool will inject it.\n"
            . "- Use telegram_api_schema if you need exact Telegram Bot API method signatures."
        );

        for ($step = 0; $step < self::MAX_RESPONSE_STEPS; ++$step) {
            $response = yield $this->agenticActivity->respondFromMemory(
                memory: $responseMemory,
                tools: AgenticToolset::RESPONSE_TOOLS,
                skills: AgenticToolset::RESPONSE_SKILLS,
                chatId: $this->input->chatId,
            );

            if ($response instanceof ErrorResponse) {
                $errorMessage = $response->message ?? 'Unknown error.';
                yield $this->sendMessage('Произошла ошибка: ' . $errorMessage);

                return;
            }

            $choice = $response->choices[0] ?? null;
            if ($choice === null) {
                return;
            }

            yield $this->agenticActivity->saveResponseMessage(
                chatId: $this->input->chatId,
                topicId: null,
                message: $choice->message,
                rawResponse: $response,
            );

            $assistantMessage = $choice->message;
            $toolCalls = $assistantMessage->toolCalls ?? [];

            if ($toolCalls === []) {
                $content = $assistantMessage->content === null ? '' : trim($assistantMessage->content);
                if ($content === '') {
                    continue;
                }

                $this->workingMemory->add($assistantMessage);

                yield $this->sendMessage($content);

                return;
            }

            $executableToolCalls = array_values(array_filter(
                $toolCalls,
                static fn (object $toolCall): bool => $toolCall instanceof KnownFunctionCall
                    || $toolCall instanceof UnknownFunctionCall,
            ));

            if ($executableToolCalls === []) {
                continue;
            }

            $assistantToolMessage = count($executableToolCalls) === count($toolCalls)
                ? $assistantMessage
                : AssistantMessage::withToolCalls($assistantMessage, $executableToolCalls);

            $responseMemory[] = $assistantToolMessage;
            $this->workingMemory->add($assistantToolMessage);
            $hasTerminalUserNotification = false;

            foreach ($executableToolCalls as $toolCall) {
                if ($toolCall instanceof KnownFunctionCall) {
                    $toolResult = yield $this->executeTool(
                        toolName: $toolCall->tool,
                        arguments: $toolCall->arguments,
                    );

                    if (
                        $toolCall->arguments instanceof TelegramApiCall
                        && TelegramApiCallExecutor::isTerminalMethod($toolCall->arguments->method)
                    ) {
                        $hasTerminalUserNotification = true;
                    }

                    if ($toolCall->arguments instanceof UpsertRuntimeSkill || $toolCall->arguments instanceof UpsertRuntimeTool) {
                        $hasTerminalUserNotification = true;
                    }
                } else {
                    $toolResult = yield $this->executeRuntimeTool(
                        toolName: $toolCall->name,
                        argumentsJson: $toolCall->arguments,
                    );
                }

                $toolMessage = new ToolMessage(
                    content: $toolResult,
                    toolCallId: $toolCall->id,
                );

                $responseMemory[] = $toolMessage;
                $this->workingMemory->add($toolMessage);

                yield $this->agenticActivity->saveResponseMessage(
                    chatId: $this->input->chatId,
                    topicId: null,
                    message: $toolMessage,
                );
            }

            if ($hasTerminalUserNotification) {
                return;
            }
        }

        yield $this->sendMessage('Не удалось завершить ответ за допустимое число шагов.');
    }

    private function initializeCompactionClock(): void
    {
        $now = $this->currentTimestamp();

        if ($this->lastActivityAt === 0) {
            $this->lastActivityAt = $now;
        }

        if ($this->lastCompactionAt === 0) {
            $this->lastCompactionAt = $now;
        }
    }

    private function shouldCompactNow(): bool
    {
        $now = $this->currentTimestamp();

        $periodicCompactionDue = ($now - $this->lastCompactionAt) >= self::COMPACTION_INTERVAL_SECONDS;
        $idleCompactionDue = $this->lastActivityAt > $this->lastCompactionAt
            && ($now - $this->lastActivityAt) >= self::IDLE_COMPACTION_AFTER_SECONDS;
        $sizeCompactionDue = $this->workingMemory->shouldCompactBySize();

        if (!$periodicCompactionDue && !$idleCompactionDue && !$sizeCompactionDue) {
            return false;
        }

        return $now >= $this->compactionRetryAfter;
    }

    private function waitForUpdatesOrWorkflowDeadline(): Generator
    {
        yield Workflow::awaitWithTimeout(
            $this->secondsUntilNextWorkflowDeadline(),
            fn (): bool => $this->updatesQueue->has(),
        );
    }

    private function secondsUntilNextWorkflowDeadline(): int
    {
        $now = $this->currentTimestamp();
        $deadlines = [$this->nextCompactionDeadline($now)];

        if ($this->hasPendingPipeline()) {
            $deadlines[] = $this->pipelinePendingSince + self::PIPELINE_BATCH_WINDOW_SECONDS;
        }

        return max(1, min($deadlines) - $now);
    }

    private function nextCompactionDeadline(int $now): int
    {
        $deadlines = [
            $this->lastCompactionAt + self::COMPACTION_INTERVAL_SECONDS,
        ];

        if ($this->workingMemory->shouldCompactBySize()) {
            $deadlines[] = $now;
        }

        if ($this->lastActivityAt > $this->lastCompactionAt) {
            $deadlines[] = $this->lastActivityAt + self::IDLE_COMPACTION_AFTER_SECONDS;
        }

        $deadline = min($deadlines);

        if ($deadline <= $now && $this->compactionRetryAfter > $now) {
            $deadline = $this->compactionRetryAfter;
        }

        return $deadline;
    }

    private function hasPendingPipeline(): bool
    {
        return $this->pipelinePendingSince > 0;
    }

    private function shouldRunPipelineNow(): bool
    {
        return $this->shouldRunPipelineAt($this->currentTimestamp());
    }

    private function shouldRunPipelineAt(int $now): bool
    {
        return $this->hasPendingPipeline()
            && ($now - $this->pipelinePendingSince) >= self::PIPELINE_BATCH_WINDOW_SECONDS;
    }

    private function compactWorkingMemory(): Generator
    {
        if (!$this->workingMemory->hasMessagesToCompact()) {
            $this->markCompactionSucceeded();
            return;
        }

        $messagesToCompact = $this->workingMemory->getMessagesToCompact();

        if ($messagesToCompact === []) {
            $this->workingMemory->compact('No compacted context.');
            $this->markCompactionSucceeded();
            return;
        }

        $compactedContext = yield $this->agenticActivity->compactWorkingMemory(
            existingCompactedContext: $this->workingMemory->getCompactedContext(),
            memory: $messagesToCompact,
        );

        if (!is_string($compactedContext)) {
            $this->markCompactionFailed();
            return;
        }

        $this->workingMemory->compact($compactedContext);
        $this->markCompactionSucceeded();
    }

    private function markCompactionSucceeded(): void
    {
        $this->lastCompactionAt = $this->currentTimestamp();
        $this->compactionRetryAfter = 0;
        $this->consecutiveCompactionFailures = 0;
    }

    private function markCompactionFailed(): void
    {
        ++$this->consecutiveCompactionFailures;

        if (
            $this->consecutiveCompactionFailures >= self::MAX_COMPACTION_FAILURES_BEFORE_DROP
            && $this->workingMemory->shouldCompactBySize()
        ) {
            $this->workingMemory->dropMessagesToCompact();
            $this->lastCompactionAt = $this->currentTimestamp();
            $this->compactionRetryAfter = 0;
            $this->consecutiveCompactionFailures = 0;
            return;
        }

        $this->compactionRetryAfter = $this->currentTimestamp()
            + self::compactionRetryDelaySeconds($this->consecutiveCompactionFailures);
    }

    private static function compactionRetryDelaySeconds(int $failureCount): int
    {
        return min(
            self::MAX_COMPACTION_RETRY_AFTER_SECONDS,
            self::COMPACTION_RETRY_AFTER_SECONDS * (2 ** max(0, $failureCount - 1)),
        );
    }

    private function currentTimestamp(): int
    {
        return Workflow::now()->getTimestamp();
    }

    public function executeTool(string $toolName, object $arguments): Generator
    {
        $separatorPosition = strrpos($toolName, '\\');
        $shortClassName = $separatorPosition === false
            ? $toolName
            : substr($toolName, $separatorPosition + 1);

        return yield Workflow::executeActivity(
            $shortClassName . 'Executor.execute',
            [$this->input->chatId, $arguments],
            options: ActivityOptions::new()
                ->withStartToCloseTimeout(CarbonInterval::minute())
                ->withRetryOptions(
                    RetryOptions::new()->withNonRetryableExceptions([])
                )
        );
    }

    private function executeRuntimeTool(string $toolName, string $argumentsJson): Generator
    {
        return yield Workflow::executeActivity(
            'RuntimeToolExecutor.execute',
            [$this->input->chatId, $toolName, $argumentsJson],
            options: ActivityOptions::new()
                ->withStartToCloseTimeout(CarbonInterval::minute())
                ->withRetryOptions(
                    RetryOptions::new()->withNonRetryableExceptions([])
                )
        );
    }

    private function shouldContinueAsNew(): bool
    {
        return $this->processedSinceContinueAsNew >= self::MAX_UPDATES_BEFORE_CONTINUE;
    }

    private function continueAsNew(): Generator
    {
        $input = new AgenticWorkflowInput(
            chatId: $this->input->chatId,
            processedCount: $this->processedCount,
            workingMemory: $this->workingMemory->get(),
            compactedContext: $this->workingMemory->getCompactedContext(),
            lastActivityAt: $this->lastActivityAt,
            lastCompactionAt: $this->lastCompactionAt,
            compactionRetryAfter: $this->compactionRetryAfter,
            consecutiveCompactionFailures: $this->consecutiveCompactionFailures,
            pipelinePendingSince: $this->pipelinePendingSince,
        );

        return yield Workflow::continueAsNew(
            self::WORKFLOW_TYPE,
            [$input],
        );
    }

    private function sendMessage(string $text): Generator
    {
        return yield $this->telegramActivity->sendMessage(
            chatId: $this->input->chatId,
            text: $text,
            messageThreadId: null,
        );
    }

    private function sendTypingAction(): Generator
    {
        return yield $this->telegramActivity->sendChatAction(
            chatId: $this->input->chatId,
            action: 'typing',
            messageThreadId: null,
        );
    }

    private function startTypingIndicator(): void
    {
        $generation = ++$this->typingIndicatorGeneration;

        Workflow::async(function () use ($generation): Generator {
            try {
                while ($this->typingIndicatorGeneration === $generation) {
                    yield Workflow::timer(self::TYPING_ACTION_REFRESH_INTERVAL_SECONDS);

                    if ($this->typingIndicatorGeneration !== $generation) {
                        return;
                    }

                    yield $this->sendTypingAction();
                }
            } catch (CanceledFailure) {
                return;
            }
        });
    }

    private function stopTypingIndicator(): void
    {
        ++$this->typingIndicatorGeneration;
    }

    #[Workflow\SignalMethod]
    public function addUpdate($update): void
    {
        $this->updatesQueue->push($update);
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
    public function getMemory(): array
    {
        return $this->workingMemory->get();
    }

    #[Workflow\QueryMethod]
    public function getCompactedContext(): string
    {
        return $this->workingMemory->getCompactedContext();
    }
}
