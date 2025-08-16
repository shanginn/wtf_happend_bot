<?php

declare(strict_types=1);

namespace Bot\Handler;

use Bot\Service\ChatService;
use Bot\Tool\RouterTool;
use Bot\Tool\BotRoutingDecision;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Bindings\Types\ReplyParameters;
use Phenogram\Framework\Handler\UpdateHandlerInterface;
use Phenogram\Framework\TelegramBot;
use Throwable;

class IntelligentRoutingHandler implements UpdateHandlerInterface
{
    private array $lastActionTimestamp = [];
    private array $messageCountSinceAction = [];

    public function __construct(
        private readonly RouterTool $routerTool,
        private readonly ChatService $chatService,
    ) {}

    public function handle(UpdateInterface $update, TelegramBot $bot): void
    {
        $userId = $update->message->from->id;
        $chatId = $update->message->chat->id;
        $messageText = $update->message->text;
        $username = $update->message->from->username ?? $update->message->from->firstName ?? null;
        
        // Check if message is a reply to bot
        $isReplyToBot = $this->isReplyToBot($update, $bot);
        
        // Get chat context and message count
        $chatContext = $this->getChatContext($chatId);
        $messagesSinceLastAction = $this->getMessagesSinceLastAction($chatId);

        try {
            // First try quick routing
            $decision = $this->routerTool->quickRoute($messageText, $isReplyToBot);
            
            // If no quick decision, use AI routing
            if ($decision === null) {
                $decision = $this->routerTool->routeMessage(
                    messageText: $messageText,
                    chatContext: $chatContext,
                    username: $username,
                    isReplyToBot: $isReplyToBot,
                    messagesSinceLastAction: $messagesSinceLastAction
                );
            }

            // Log decision for debugging
            dump("Router decision for '{$messageText}': {$decision->action} - {$decision->reason} (confidence: {$decision->confidence})");

            // Act on decision
            switch ($decision->action) {
                case 'reply':
                    $this->handleReply($update, $bot);
                    $this->updateActionTimestamp($chatId);
                    break;
                
                case 'summarize':
                    $this->handleSummarization($update, $bot);
                    $this->updateActionTimestamp($chatId);
                    break;
                
                case 'silent':
                    $this->incrementMessageCount($chatId);
                    // Do nothing - stay silent
                    break;
                
                default:
                    dump("Unknown routing decision: {$decision->action}");
                    $this->incrementMessageCount($chatId);
            }

        } catch (Throwable $e) {
            dump('Routing error: ' . $e->getMessage());
            // Fallback to silent behavior on error
            $this->incrementMessageCount($chatId);
        }
    }

    private function handleReply(UpdateInterface $update, TelegramBot $bot): void
    {
        $userId = $update->message->from->id;
        $chatId = $update->message->chat->id;
        $userMessage = $update->message->text;

        try {
            $response = $this->chatService->generateResponseWithMemory($userMessage, $chatId, $userId);

            if ($response === null) {
                $bot->api->sendMessage(
                    chatId: $chatId,
                    text: "Извините, произошла ошибка при обработке вашего запроса."
                );
                return;
            }

            $bot->api->sendMessage(
                chatId: $chatId,
                text: $response,
                replyParameters: new ReplyParameters(messageId: $update->message->messageId, allowSendingWithoutReply: true),
            );
        } catch (Throwable $e) {
            dump('Reply generation error: ' . $e->getMessage());
            $bot->api->sendMessage(
                chatId: $chatId,
                text: "Произошла ошибка при обработке сообщения." . $e->getMessage()
            );
        }
    }

    private function handleSummarization(UpdateInterface $update, TelegramBot $bot): void
    {
        $userId = $update->message->from->id;
        $chatId = $update->message->chat->id;

        try {
            $summary = $this->chatService->summarize($chatId, $userId);

            if ($summary === false) {
                $bot->api->sendMessage(
                    chatId: $chatId,
                    text: "Недостаточно сообщений для создания сводки. Минимум 10 новых сообщений.",
                    replyParameters: new ReplyParameters(messageId: $update->message->messageId, allowSendingWithoutReply: true),
                );
                return;
            }

            $bot->api->sendMessage(
                chatId: $chatId,
                text: $summary,
                replyParameters: new ReplyParameters(messageId: $update->message->messageId, allowSendingWithoutReply: true),
            );
        } catch (Throwable $e) {
            dump('Summarization error: ' . $e->getMessage());
            $bot->api->sendMessage(
                chatId: $chatId,
                text: "Произошла ошибка при создании сводки."
            );
        }
    }

    private function isReplyToBot(UpdateInterface $update, TelegramBot $bot): bool
    {
        if ($update->message->replyToMessage === null) {
            return false;
        }

        // Check if replying to bot's message
        return $update->message->replyToMessage->from->id === $bot->api->getMe()->id;
    }

    private function getChatContext(int $chatId): string
    {
        // Simple context - could be enhanced with recent message analysis
        return "General chat conversation";
    }

    private function getMessagesSinceLastAction(int $chatId): int
    {
        return $this->messageCountSinceAction[$chatId] ?? 0;
    }

    private function updateActionTimestamp(int $chatId): void
    {
        $this->lastActionTimestamp[$chatId] = time();
        $this->messageCountSinceAction[$chatId] = 0;
    }

    private function incrementMessageCount(int $chatId): void
    {
        $this->messageCountSinceAction[$chatId] = ($this->messageCountSinceAction[$chatId] ?? 0) + 1;
    }

    public static function supports(UpdateInterface $update): bool
    {
        return $update->message?->text !== null &&
               !str_starts_with($update->message->text, '/');
    }
}