<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Activity\TelegramActivity;
use Bot\Agent\OpenaiMessageTransformer;
use Bot\Llm\Tools\Decision\RespondDecision;
use Bot\Llm\Tools\Memory\SaveMemory;
use Carbon\CarbonInterval;
use Generator;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\Assistant\KnownFunctionCall;
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
class AgenticWorkflow
{
    private const int MAX_RESPONSE_STEPS = 15;
    private const int RESPONSE_TOOL_CONTEXT_TOKEN_BUDGET = 128000;

    private AgenticActivity|ActivityProxy $agenticActivity;
    private TelegramActivity|ActivityProxy $telegramActivity;
    private MessageQueue $updatesQueue;
    private AgenticWorkflowInput $input;
    private int $processedCount = 0;

    private WorkingMemory $workingMemory;

    public function __construct()
    {
        $this->agenticActivity = AgenticActivity::getDefinition();
        $this->telegramActivity = TelegramActivity::getDefinition();
        $this->updatesQueue = new MessageQueue();
        $this->workingMemory = new WorkingMemory();
    }

    #[WorkflowMethod]
    #[ReturnType(Type::TYPE_STRING)]
    public function create(AgenticWorkflowInput $input): Generator
    {
        $this->input = $input;

        do {
            yield Workflow::await(fn (): bool => $this->updatesQueue->has());

            $updates = $this->updatesQueue->flush();

            foreach ($updates as $update) {
                yield $this->telegramActivity->saveUpdates($update);
            }

            foreach ($updates as $update) {
                $inputMessageView = yield $this->telegramActivity->updateToView($update);
                $userMessageView = OpenaiMessageTransformer::toChatUserMessage($inputMessageView);

                $this->workingMemory->add($userMessageView);
            }

            $this->processedCount += count($updates);

            yield from $this->runAgentLoop();
        } while (true);
    }

    private function runAgentLoop(): Generator
    {
        $tools = [
            RespondDecision::class,
            SaveMemory::class,
        ];

        $result = yield $this->agenticActivity->memoryComplete(
            memory: $this->workingMemory->get(),
            tools: $tools,
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

        $shouldRespond = false;

        $toolsResults = [];
        foreach ($assistantMessage->toolCalls ?? [] as $toolCall) {
            if (!$toolCall instanceof KnownFunctionCall) {
                continue;
            }

            if ($toolCall->arguments instanceof RespondDecision) {
                $shouldRespond = $toolCall->arguments->shouldRespond;
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

            $toolsResults[] = $toolMessage;

            yield $this->agenticActivity->saveResponseMessage(
                chatId: $this->input->chatId,
                topicId: null,
                message: $toolMessage,
            );
        }

        if ($shouldRespond && $assistantMessage->content !== null) {
            $content = trim($assistantMessage->content);

            if ($content !== '') {
                $this->workingMemory->add($assistantMessage);

                yield $this->sendMessage($content);
            }
        }

        foreach ($toolsResults as $toolMessage) {
            $this->workingMemory->add($toolMessage);
        }

        if ($shouldRespond) {
            yield from $this->respond();
        }
    }

    private function respond(): Generator
    {
        $memorySelection = yield $this->agenticActivity->recollectRelevantMemories(
            chatId: $this->input->chatId,
            history: $this->workingMemory->get(),
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

        $responseMemory = $this->workingMemory->get();
        $responseMemory[] = new UserMessage(
            "Relevant participant memories for this reply:\n{$relevantMemories}",
        );

        for ($step = 0; $step < self::MAX_RESPONSE_STEPS; ++$step) {
            $response = yield $this->agenticActivity->respondFromMemory(
                memory: $responseMemory,
                tools: AgenticToolset::RESPONSE_TOOLS,
                skills: AgenticToolset::RESPONSE_SKILLS,
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

            $responseMemory[] = $assistantMessage;
            $this->workingMemory->add($assistantMessage);

            foreach ($toolCalls as $toolCall) {
                if (!$toolCall instanceof KnownFunctionCall) {
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

                $responseMemory[] = $toolMessage;
                $this->workingMemory->add($toolMessage);

                yield $this->agenticActivity->saveResponseMessage(
                    chatId: $this->input->chatId,
                    topicId: null,
                    message: $toolMessage,
                );
            }
        }

        yield $this->sendMessage('Не удалось завершить ответ за допустимое число шагов.');
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

    private function sendMessage(string $text): Generator
    {
        return yield $this->telegramActivity->sendMessage(
            chatId: $this->input->chatId,
            text: $text,
            messageThreadId: null,
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
    public function getMemory(): array
    {
        return $this->workingMemory->get();
    }
}
