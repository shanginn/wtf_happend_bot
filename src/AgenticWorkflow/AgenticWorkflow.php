<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Activity\DatabaseActivity;
use Bot\Activity\TelegramActivity;
use Bot\Llm\Skills\ImageAnalysisSkill;
use Bot\Llm\Skills\QuestionAnsweringSkill;
use Bot\Llm\Skills\SummarizationSkill;
use Bot\Llm\Tools\Chat\CreatePoll;
use Bot\Llm\Tools\Chat\GetCurrentTime;
use Bot\Llm\Tools\Chat\SearchMessages;
use Bot\Llm\Tools\Decision\RespondDecision;
use Bot\Llm\Tools\Memory\RecallMemory;
use Bot\Llm\Tools\Memory\SaveMemory;
use Generator;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\Assistant\KnownFunctionCall;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Shanginn\Openai\ChatCompletion\Message\ToolMessage;
use Temporal\DataConverter\Type;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Temporal\Workflow\ReturnType;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
class AgenticWorkflow
{
    private const int MAX_AGENT_STEPS = 8;

    /** @var array<class-string> */
    private const array SKILLS = [
        SummarizationSkill::class,
        QuestionAnsweringSkill::class,
        ImageAnalysisSkill::class,
    ];

    /** @var array<class-string> */
    private const array AGENTIC_TOOLS = [
        RespondDecision::class,
        SaveMemory::class,
        RecallMemory::class,
        SearchMessages::class,
        CreatePoll::class,
        GetCurrentTime::class,
    ];

    private AgenticActivity|ActivityProxy $agenticActivity;
    private DatabaseActivity|ActivityProxy $databaseActivity;
    private TelegramActivity|ActivityProxy $telegramActivity;
    private MessageQueue $updatesQueue;
    /** @var array<MessageInterface> */
    private array $history = [];
    private AgenticWorkflowInput $input;
    private int $processedCount = 0;

    public function __construct()
    {
        $this->agenticActivity = AgenticActivity::getDefinition();
        $this->databaseActivity = DatabaseActivity::getDefinition();
        $this->telegramActivity = TelegramActivity::getDefinition();
        $this->updatesQueue = new MessageQueue();
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

            $this->processedCount += count($updates);

            yield from $this->runAgentLoop();
        } while (true);
    }

    private function runAgentLoop(): Generator
    {
        for ($step = 0; $step < self::MAX_AGENT_STEPS; $step++) {
            /** @var CompletionResponse|ErrorResponse $result */
            $result = yield $this->agenticActivity->complete(
                chatId: $this->input->chatId,
                threadId: $this->input->messageThreadId,
                tools: self::AGENTIC_TOOLS,
                skills: self::SKILLS,
            );

            if ($result instanceof ErrorResponse) {
                $errorMessage = $result->message ?? 'Unknown error.';
                yield $this->sendMessage('Произошла ошибка: ' . $errorMessage);

                return;
            }

            if ($result->choices === []) {
                return;
            }

            $choice = $result->choices[0] ?? null;
            if ($choice === null) {
                return;
            }

            $assistantMessage = $choice->message;
            $this->history[] = $assistantMessage;

            $toolCalls = $assistantMessage->toolCalls ?? [];
            $pendingToolCalls = [];
            $decision = null;

            foreach ($toolCalls as $toolCall) {
                if (!$toolCall instanceof KnownFunctionCall) {
                    continue;
                }

                if ($toolCall->tool === RespondDecision::class) {
                    /** @var RespondDecision $arguments */
                    $arguments = $toolCall->arguments;
                    $decision = $arguments;
                    continue;
                }

                $pendingToolCalls[] = $toolCall;
            }

            if ($pendingToolCalls !== []) {
                foreach ($pendingToolCalls as $toolCall) {
                    $toolResult = yield from $this->executeToolCall($toolCall);
                    $this->history[] = new ToolMessage(
                        content: $toolResult,
                        toolCallId: $toolCall->id,
                    );
                }

                continue;
            }

            if ($decision instanceof RespondDecision) {
                if ($decision->shouldRespond && trim($decision->response) !== '') {
                    yield $this->sendMessage($decision->response);
                }

                return;
            }

            $content = trim($assistantMessage->content ?? '');
            if ($content !== '') {
                yield $this->sendMessage($content);
            }

            return;
        }

        yield $this->sendMessage(
            'I reached the internal tool loop limit before I could finish. Please try again.',
        );
    }

    private function executeToolCall(KnownFunctionCall $toolCall): Generator
    {
        $arguments = $toolCall->arguments;


    }

    private function sendMessage(string $text): Generator
    {
        return yield $this->telegramActivity->sendMessage(
            chatId: $this->input->chatId,
            text: $text,
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
}
