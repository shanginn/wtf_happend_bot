<?php

declare(strict_types=1);

namespace Bot\Handler;

use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Framework\Handler\AbstractCommandHandler;
use Phenogram\Framework\TelegramBot;
use Temporal\Client\GRPC\StatusCode;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Exception\Client\ServiceClientException;

class ClearCommandHandler extends AbstractCommandHandler
{
    private const string COMMAND = '/clear';
    private const string COMMAND_PATTERN = '/^\/clear(?:@[\pL\pN_]+)?$/u';
    private const string SUCCESS_MESSAGE = 'Текущий workflow чата остановлен. Следующее сообщение запустит новый.';
    private const string NOOP_MESSAGE = 'Активного workflow для этого чата уже нет.';

    public function __construct(
        private readonly WorkflowClientInterface $client,
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

        $responseText = self::SUCCESS_MESSAGE;

        try {
            $workflow = $this->client->newUntypedRunningWorkflowStub(
                sprintf('Chat %d [Root]', $message->chat->id),
            );
            $workflow->terminate(
                reason: 'Cleared by /clear command',
                details: ['updateId' => $update->updateId],
            );
        } catch (ServiceClientException $e) {
            if ($e->getCode() !== StatusCode::NOT_FOUND) {
                throw $e;
            }

            $responseText = self::NOOP_MESSAGE;
        }

        $bot->api->sendMessage(
            chatId: $message->chat->id,
            text: $responseText,
            messageThreadId: $message->messageThreadId,
        );
    }
}
