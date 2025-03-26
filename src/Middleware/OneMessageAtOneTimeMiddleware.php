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
        $chatId = $update->message->chat->id;
        if (array_key_exists($chatId, $this->activeMessages)) {
            $bot->api->sendMessage(
                chatId: $chatId,
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

        $this->activeMessages[$chatId] = true;

        $handler->handle($update, $bot);

        unset($this->activeMessages[$chatId]);
    }

    public function reactivateChat(int $chatId): void
    {
        unset($this->activeMessages[$chatId]);
    }
}
