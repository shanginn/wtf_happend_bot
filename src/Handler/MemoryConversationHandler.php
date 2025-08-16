<?php

declare(strict_types=1);

namespace Bot\Handler;

use Bot\Service\ChatService;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Framework\TelegramBot;
use Throwable;

class MemoryConversationHandler
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {}

    public function __invoke(UpdateInterface $update, TelegramBot $bot): void
    {
        $userId = $update->message->from->id;
        $chatId = $update->message->chat->id;
        $userMessage = $update->message->text;

        try {
            $response = $this->chatService->generateResponseWithMemory($userMessage, $chatId, $userId);

            if ($response === null) {
                $bot->api->sendMessage(
                    chatId: $chatId,
                    text: "Извините, произошла ошибка при обработке вашего запроса. Попробуйте позже."
                );
                return;
            }

            $bot->api->sendMessage(
                chatId: $chatId,
                text: $response
            );
        } catch (Throwable $e) {
            error_log('Memory conversation error: ' . $e->getMessage());
            $bot->api->sendMessage(
                chatId: $chatId,
                text: "Произошла ошибка при обработке вашего сообщения: " . $e->getMessage()
            );
        }
    }

    public static function supports(UpdateInterface $update): bool
    {
        return $update->message?->text !== null &&
               !str_starts_with($update->message->text, '/');
    }
}