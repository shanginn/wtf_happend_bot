<?php

declare(strict_types=1);

namespace Bot\RouterWorkflow;

use Bot\Activity\LlmActivity;
use Bot\Activity\TelegramActivity;
use Bot\Telegram\Update;
use Carbon\CarbonInterval;
use Generator;
use Phenogram\Bindings\Serializer;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\Assistant\KnownFunctionCall;
use Shanginn\Openai\ChatCompletion\Message\AssistantMessage;
use Shanginn\Openai\ChatCompletion\Message\SystemMessage;
use Shanginn\Openai\ChatCompletion\Message\ToolMessage;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\DataConverter\Type;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Temporal\Workflow\ReturnType;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
class RouterWorkflow
{
    private const int MAX_UPDATES_BEFORE_CONTINUE = 100;
    private const int MAX_HISTORY_MESSAGES = 50;

    private RouterActivity|ActivityProxy $service;
    private MessageQueue $updatesQueue;
    private LlmActivity|ActivityProxy $llmService;
    private array $history = [];
    private Serializer $telegramSerializer;
    private TelegramActivity|ActivityProxy $botActivity;
    private RouterWorkflowInput $input;
    private int $processedCount = 0;

    public function __construct()
    {
        $this->service = RouterActivity::getDefinition();
        $this->llmService = LlmActivity::getDefinition();
        $this->telegramSerializer = new Serializer();
        $this->botActivity = TelegramActivity::getDefinition();
        $this->updatesQueue = new MessageQueue();
    }

    #[WorkflowMethod]
    #[ReturnType(Type::TYPE_STRING)]
    public function create(RouterWorkflowInput $input): Generator
    {
        $this->input = $input;
        $this->processedCount = $input->processedCount;

        if (!empty($input->summarizedHistory)) {
            $this->restoreHistoryFromSummary($input->summarizedHistory);
        }

        do {
            if ($this->shouldContinueAsNew()) {
                return yield $this->continueAsNew();
            }

            yield Workflow::await(fn () => $this->updatesQueue->has());

            $updates = $this->updatesQueue->flush();

            $shouldRespond = yield $this->service->shouldRespond($updates);

            if (!$shouldRespond) {
                $this->processedCount += count($updates);
                continue;
            }

            /** @var ErrorResponse|CompletionResponse $res */
            $res = yield $this->service->processUpdate($updates, $this->history);

            if ($res instanceof ErrorResponse) {
                $errorMessage = $res->message ?? 'Unknown error.';
                yield $this->sendMessage(
                    "Error processing your request: " . $errorMessage,
                );
                continue;
            }

            if (count($res->choices) === 0) {
                continue;
            }

            $this->processedCount += count($updates);
            $this->history[] = new UserMessage(content: json_encode($this->telegramSerializer->serialize($updates)));

            $choice = array_shift($res->choices);
            if ($choice === null) {
                continue;
            }

            $message = $choice->message;
            $this->history[] = $message;

            if ($message->content !== null && trim($message->content) !== '') {
                yield $this->sendMessage($message->content);
            }

            $toolCalls = $message->toolCalls;

            foreach ($toolCalls ?? [] as $toolCall) {
                if (!is_a($toolCall, KnownFunctionCall::class, true)) {
                    yield $this->sendMessage(
                        "I cannot perform the requested action: unknown function"
                    );
                    continue;
                }

                $toolName = $toolCall->tool;
                $arguments = $toolCall->arguments;

                Workflow::async(function () use ($toolName, $arguments, $toolCall) {
                    yield $this->sendMessage("Executing: " . $toolName);

                    $shortClassName = substr($toolName, strrpos($toolName, '\\') + 1);

                    $toolExecutionResult = yield $this->executeTool(
                        toolName: $shortClassName,
                        arguments: $arguments,
                    );

                    $resultString = is_string($toolExecutionResult) ? $toolExecutionResult : (is_null($toolExecutionResult) ? 'null' : json_encode($toolExecutionResult));
                    yield $this->sendMessage("Done. Result: " . $resultString);

                    $this->history[] = new ToolMessage(
                        content: $resultString,
                        toolCallId: $toolCall->id,
                    );
                });
            }

            $this->trimHistoryIfNeeded();
        } while (true);
    }

    private function shouldContinueAsNew(): bool
    {
        return $this->processedCount >= self::MAX_UPDATES_BEFORE_CONTINUE;
    }

    private function continueAsNew(): Generator
    {
        $summarizedHistory = $this->summarizeHistory();

        $newInput = new RouterWorkflowInput(
            chatId: $this->input->chatId,
            messageThreadId: $this->input->messageThreadId,
            processedCount: $this->processedCount,
            summarizedHistory: $summarizedHistory,
        );

        return yield Workflow::continueAsNew(
            self::class,
            [$newInput]
        );
    }

    private function summarizeHistory(): array
    {
        if (empty($this->history)) {
            return [];
        }

        $lastMessages = array_slice($this->history, -10);

        $summary = [
            'processedCount' => $this->processedCount,
            'messageCount' => count($this->history),
            'lastMessages' => [],
        ];

        foreach ($lastMessages as $msg) {
            $summary['lastMessages'][] = [
                'role' => $msg::class === UserMessage::class ? 'user' : 
                         ($msg::class === AssistantMessage::class ? 'assistant' : 'system'),
                'preview' => mb_substr($msg->content ?? '', 0, 200),
            ];
        }

        return $summary;
    }

    private function restoreHistoryFromSummary(array $summary): void
    {
        if (isset($summary['lastMessages']) && !empty($summary['lastMessages'])) {
            $systemContent = sprintf(
                'Conversation continued from previous session. %d messages processed earlier.',
                $summary['processedCount'] ?? 0
            );
            $this->history[] = new SystemMessage($systemContent);
        }
    }

    private function trimHistoryIfNeeded(): void
    {
        if (count($this->history) > self::MAX_HISTORY_MESSAGES) {
            $systemMessages = array_filter(
                $this->history,
                fn($msg) => $msg instanceof SystemMessage
            );

            $recentMessages = array_slice($this->history, -self::MAX_HISTORY_MESSAGES + 1);

            $this->history = array_merge($systemMessages, $recentMessages);
        }
    }

    public function executeTool(string $toolName, object $arguments): Generator
    {
        return yield Workflow::executeActivity(
            $toolName . '.execute',
            [$arguments],
            options: ActivityOptions::new()
                ->withStartToCloseTimeout(CarbonInterval::minute())
                ->withRetryOptions(
                    RetryOptions::new()->withNonRetryableExceptions([])
                )
        );
    }

    public function sendMessage(string $message): Generator
    {
        return yield $this->botActivity->sendMessage(
            chatId: $this->input->chatId,
            text: $message,
            messageThreadId: $this->input->messageThreadId,
        );
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
    public function getHistorySize(): int
    {
        return count($this->history);
    }
}
