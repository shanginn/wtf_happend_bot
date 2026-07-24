<?php

declare(strict_types=1);

namespace Bot\Handler;

use Bot\AgenticWorkflow\AgenticWorkflow;
use Bot\AgenticWorkflow\AgenticWorkflowHandler;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Framework\Handler\AbstractCommandHandler;
use Phenogram\Framework\TelegramBot;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Exception\Client\WorkflowNotFoundException;

class WorkflowControlCommandHandler extends AbstractCommandHandler
{
    private const string PAUSE_COMMAND_PATTERN = '/^\/pause(?:@[\pL\pN_]+)?$/u';
    private const string RESUME_COMMAND_PATTERN = '/^\/resume(?:@[\pL\pN_]+)?$/u';
    private const string PAUSED_MESSAGE = 'Workflow чата приостановлен. Новые сообщения будут ждать команды /resume.';
    private const string RESUMED_MESSAGE = 'Workflow чата продолжил работу и обработает накопленные сообщения.';
    private const string NO_WORKFLOW_MESSAGE = 'Активного workflow для этого чата нет.';

    public function __construct(
        private readonly WorkflowClientInterface $client,
    ) {}

    public static function supports(UpdateInterface $update): bool
    {
        return self::commandFor($update) !== null;
    }

    public function handle(UpdateInterface $update, TelegramBot $bot): void
    {
        $message = $update->message;
        $command = self::commandFor($update);

        if ($message === null || $command === null) {
            return;
        }

        $responseText = $command === AgenticWorkflow::PAUSE_SIGNAL_NAME
            ? self::PAUSED_MESSAGE
            : self::RESUMED_MESSAGE;

        try {
            $workflow = $this->client->newUntypedRunningWorkflowStub(
                AgenticWorkflowHandler::generateWorkflowIdForChat($message->chat->id),
                null,
                AgenticWorkflow::WORKFLOW_TYPE,
            );
            $workflow->signal($command);
        } catch (WorkflowNotFoundException) {
            $responseText = self::NO_WORKFLOW_MESSAGE;
        }

        $bot->api->sendMessage(
            chatId: $message->chat->id,
            text: $responseText,
            messageThreadId: $message->messageThreadId,
        );
    }

    private static function commandFor(UpdateInterface $update): ?string
    {
        if ($update->message === null) {
            return null;
        }

        foreach (self::extractCommands($update->message) as $command) {
            if (preg_match(self::PAUSE_COMMAND_PATTERN, $command) === 1) {
                return AgenticWorkflow::PAUSE_SIGNAL_NAME;
            }

            if (preg_match(self::RESUME_COMMAND_PATTERN, $command) === 1) {
                return AgenticWorkflow::RESUME_SIGNAL_NAME;
            }
        }

        return null;
    }
}
