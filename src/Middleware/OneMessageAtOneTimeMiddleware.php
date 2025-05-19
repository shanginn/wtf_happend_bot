<?php

declare(strict_types=1);

namespace Bot\Middleware;

use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Bindings\Types\ReplyParameters;
use Phenogram\Framework\Handler\UpdateHandlerInterface;
use Phenogram\Framework\Middleware\MiddlewareInterface;
use Phenogram\Framework\TelegramBot;

class OneMessageAtOneTimeMiddleware implements MiddlewareInterface
{
    private array $activeMessages = [];

    public function process(UpdateInterface $update, UpdateHandlerInterface $handler, TelegramBot $bot): void
    {
        $fromId = $update->message->from->id;
        if (array_key_exists($fromId, $this->activeMessages)) {
            $bot->api->sendMessage(
                chatId: $update->message->chat->id,
                text: <<<'TXT'
                    <i>Пожалуйста, дождитесь ответа на предыдущее сообщение
                    перед отправкой следующего.
                    </i>
                    TXT,
                parseMode: 'HTML',
                replyParameters: new ReplyParameters(
                    messageId: $update->message->messageId,
                    allowSendingWithoutReply: true,
                ),
            );

            return;
        }

        $this->activeMessages[$fromId] = true;

        $handler->handle($update, $bot);

        unset($this->activeMessages[$fromId]);
    }

    public function reactivateChat(int $chatId): void
    {
        unset($this->activeMessages[$chatId]);
    }
}
