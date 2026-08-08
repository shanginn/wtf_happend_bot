<?php

declare(strict_types=1);

namespace Bot\Handler;

use Bot\AgenticWorkflow\AgenticWorkflow;
use Bot\AgenticWorkflow\AgenticWorkflowHandler;
use Bot\Durability\DurableCommandReplyGateway;
use Bot\Telegram\TelegramChatAuthorizationPolicy;
use Bot\Telegram\TelegramTopicRouting;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Framework\Handler\AbstractCommandHandler;
use Phenogram\Framework\TelegramBot;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Exception\Client\WorkflowNotFoundException;
use Throwable;

class WorkflowControlCommandHandler extends AbstractCommandHandler
{
    private const string PAUSE_COMMAND_PATTERN  = '/^\/pause(?:@[\pL\pN_]+)?$/u';
    private const string RESUME_COMMAND_PATTERN = '/^\/resume(?:@[\pL\pN_]+)?$/u';
    private const string PAUSED_MESSAGE         = 'Workflow темы приостановлен. Новые сообщения сохраняются в историю, но не обрабатываются задним числом.';
    private const string RESUMED_MESSAGE        = 'Workflow темы продолжил работу. Новые сообщения снова обрабатываются.';
    private const string NO_WORKFLOW_MESSAGE    = 'Активного workflow для этого чата нет.';
    private const string DENIED_MESSAGE         = 'Недостаточно прав: в личном чате команду может выполнить '
        . 'только его пользователь, а в группе — владелец или администратор.';
    private const string AUTHORIZATION_FAILURE_MESSAGE = 'Не удалось проверить права в Telegram. '
        . 'Workflow не изменён; попробуйте ещё раз позже.';

    public function __construct(
        private readonly WorkflowClientInterface $client,
        private readonly TelegramChatAuthorizationPolicy $authorization,
        private readonly DurableCommandReplyGateway $durableReplies,
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

        $topicId = TelegramTopicRouting::topicId($message);

        $this->durableReplies->execute(
            updateId: $update->updateId,
            action: $command,
            chatId: $message->chat->id,
            messageThreadId: $topicId,
            messageId: $message->messageId,
            resolveReply: function () use ($message, $command, $topicId): string {
                try {
                    $authorized = $this->authorization->isMessageActorAuthorized($message);
                } catch (Throwable) {
                    return self::AUTHORIZATION_FAILURE_MESSAGE;
                }

                if (!$authorized) {
                    return self::DENIED_MESSAGE;
                }

                $responseText = $command === AgenticWorkflow::PAUSE_SIGNAL_NAME
                    ? self::PAUSED_MESSAGE
                    : self::RESUMED_MESSAGE;

                try {
                    $workflow = $this->client->newUntypedRunningWorkflowStub(
                        AgenticWorkflowHandler::generateWorkflowIdForChat(
                            $message->chat->id,
                            $topicId,
                        ),
                        null,
                        AgenticWorkflow::WORKFLOW_TYPE,
                    );
                    $workflow->signal($command);
                } catch (WorkflowNotFoundException) {
                    return self::NO_WORKFLOW_MESSAGE;
                }

                return $responseText;
            },
            sendReply: function (string $responseText) use ($bot, $message, $topicId): void {
                $bot->api->sendMessage(
                    chatId: $message->chat->id,
                    text: $responseText,
                    messageThreadId: $topicId,
                );
            },
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
