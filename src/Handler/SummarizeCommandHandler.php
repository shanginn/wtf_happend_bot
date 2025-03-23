<?php

declare(strict_types=1);

namespace Bot\Handler;

use Bot\Service\ChatService;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Bindings\Types\ReplyParameters;
use Phenogram\Framework\Handler\UpdateHandlerInterface;
use Phenogram\Framework\TelegramBot;

class SummarizeCommandHandler implements UpdateHandlerInterface
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {}

    public static function supports(UpdateInterface $update): bool
    {
        return $update->message?->text !== null
               && str_starts_with($update->message->text, '/wtf');
    }

    public function handle(UpdateInterface $update, TelegramBot $bot): void
    {
        $chatId = $update->message->chat->id;

        $summarization = $this->chatService->summarize($chatId);

        if ($summarization === false) {
            $bot->api->sendMessage(
                chatId: $chatId,
                text: 'Не найдено достаточно сообщений для обработки, читайте сами.'
            );

            return;
        }

        $bot->api->sendMessage(
            chatId: $chatId,
            text: $summarization,
            replyParameters: new ReplyParameters(messageId: $update->message->messageId),
        );
    }
}