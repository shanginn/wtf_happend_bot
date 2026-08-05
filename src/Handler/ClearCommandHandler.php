<?php

declare(strict_types=1);

namespace Bot\Handler;

use Bot\AgenticWorkflow\AgenticWorkflowHandler;
use Bot\Durability\DurableCommandReplyGateway;
use Bot\Telegram\TelegramChatAuthorizationPolicy;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Framework\Handler\AbstractCommandHandler;
use Phenogram\Framework\TelegramBot;
use Temporal\Client\GRPC\StatusCode;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Exception\Client\ServiceClientException;
use Throwable;

class ClearCommandHandler extends AbstractCommandHandler
{
    private const string COMMAND         = '/clear';
    private const string COMMAND_PATTERN = '/^\/clear(?:@[\pL\pN_]+)?$/u';
    private const string SUCCESS_MESSAGE = 'Текущий workflow чата остановлен. Следующее сообщение запустит новый.';
    private const string NOOP_MESSAGE    = 'Активного workflow для этого чата уже нет.';
    private const string DENIED_MESSAGE  = 'Недостаточно прав: в личном чате команду может выполнить '
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
        if ($update->message === null) {
            return false;
        }

        foreach (self::extractCommands($update->message) as $command) {
            if (preg_match(self::COMMAND_PATTERN, $command) === 1) {
                return true;
            }
        }

        return false;
    }

    public function handle(UpdateInterface $update, TelegramBot $bot): void
    {
        $message = $update->message;

        if ($message === null) {
            return;
        }

        $this->durableReplies->execute(
            updateId: $update->updateId,
            action: 'clear',
            chatId: $message->chat->id,
            messageThreadId: $message->messageThreadId,
            messageId: $message->messageId,
            resolveReply: function () use ($message, $update): string {
                try {
                    $authorized = $this->authorization->isMessageActorAuthorized($message);
                } catch (Throwable) {
                    return self::AUTHORIZATION_FAILURE_MESSAGE;
                }

                if (!$authorized) {
                    return self::DENIED_MESSAGE;
                }

                try {
                    $workflow = $this->client->newUntypedRunningWorkflowStub(
                        AgenticWorkflowHandler::generateWorkflowIdForChat(
                            $message->chat->id,
                            $message->messageThreadId,
                        ),
                    );
                    $workflow->terminate(
                        reason: 'Cleared by /clear command',
                        details: ['updateId' => $update->updateId],
                    );
                } catch (ServiceClientException $e) {
                    if ($e->getCode() !== StatusCode::NOT_FOUND) {
                        throw $e;
                    }

                    return self::NOOP_MESSAGE;
                }

                return self::SUCCESS_MESSAGE;
            },
            sendReply: function (string $responseText) use ($bot, $message): void {
                $bot->api->sendMessage(
                    chatId: $message->chat->id,
                    text: $responseText,
                    messageThreadId: $message->messageThreadId,
                );
            },
        );
    }
}
