<?php

declare(strict_types=1);

namespace Bot\Handler;

use Bot\Durability\DurableCommandReplyGateway;
use Bot\Space\Runtime\SpaceIdentityResolverInterface;
use Bot\Space\Workflow\SpaceAgentWorkflow;
use Bot\Space\Workflow\SpaceAgentWorkflowHandler;
use Bot\Space\Workflow\SpaceCommandInvocation;
use Bot\Telegram\TelegramChatAuthorizationPolicy;
use Bot\Telegram\TelegramTopicRouting;
use Bot\Telegram\Update;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Framework\Handler\AbstractCommandHandler;
use Phenogram\Framework\TelegramBot;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Exception\Client\WorkflowNotFoundException;
use Throwable;
use UnexpectedValueException;

class WorkflowControlCommandHandler extends AbstractCommandHandler
{
    private const string PAUSED_MESSAGE      = 'Workflow чата приостановлен. Новые сообщения сохраняются в историю, но не обрабатываются задним числом.';
    private const string RESUMED_MESSAGE     = 'Workflow чата продолжил работу. Новые сообщения снова обрабатываются.';
    private const string NO_WORKFLOW_MESSAGE = 'Активного workflow для этого чата нет.';
    private const string DENIED_MESSAGE      = 'Недостаточно прав: в личном чате команду может выполнить '
        . 'только его пользователь, а в группе — владелец или администратор.';
    private const string AUTHORIZATION_FAILURE_MESSAGE = 'Не удалось проверить права в Telegram. '
        . 'Workflow не изменён; попробуйте ещё раз позже.';

    public function __construct(
        private readonly WorkflowClientInterface $client,
        private readonly SpaceIdentityResolverInterface $spaces,
        private readonly TelegramChatAuthorizationPolicy $authorization,
        private readonly DurableCommandReplyGateway $durableReplies,
        private readonly string $botUsername,
        private readonly string $hostReleaseId = 'local',
    ) {
        if (preg_match('/\A[a-zA-Z0-9_]{5,64}\z/D', $botUsername) !== 1) {
            throw new UnexpectedValueException('Workflow control handler bot username is invalid.');
        }
    }

    public function supportsUpdate(UpdateInterface $update): bool
    {
        return $this->commandFor($update) !== null;
    }

    public function handle(UpdateInterface $update, TelegramBot $bot): void
    {
        $message = $update->message;
        $command = $this->commandFor($update);

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
            resolveReply: function () use ($message, $command, $update): string {
                try {
                    $authorized = $this->authorization->isMessageActorAuthorized($message);
                } catch (Throwable) {
                    return self::AUTHORIZATION_FAILURE_MESSAGE;
                }

                if (!$authorized) {
                    return self::DENIED_MESSAGE;
                }

                $responseText = $command === SpaceAgentWorkflow::PAUSE_SIGNAL_NAME
                    ? self::PAUSED_MESSAGE
                    : self::RESUMED_MESSAGE;

                try {
                    $workflow = $this->client->newUntypedRunningWorkflowStub(
                        $this->workflowId($update),
                        null,
                        SpaceAgentWorkflow::WORKFLOW_TYPE,
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

    private function commandFor(UpdateInterface $update): ?string
    {
        if (!$update instanceof Update) {
            return null;
        }

        $command = SpaceCommandInvocation::fromUpdate($update);
        if ($command === null || !$command->isForBot($this->botUsername)) {
            return null;
        }

        return match ($command->name) {
            SpaceAgentWorkflow::PAUSE_SIGNAL_NAME  => SpaceAgentWorkflow::PAUSE_SIGNAL_NAME,
            SpaceAgentWorkflow::RESUME_SIGNAL_NAME => SpaceAgentWorkflow::RESUME_SIGNAL_NAME,
            default                                => null,
        };
    }

    private function workflowId(UpdateInterface $update): string
    {
        if (!$update instanceof Update) {
            throw new UnexpectedValueException(
                'Space workflow commands require the bot Telegram update type.',
            );
        }

        return SpaceAgentWorkflowHandler::workflowId(
            $this->spaces->resolve($update),
            $this->hostReleaseId,
        );
    }
}
