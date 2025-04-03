<?php

declare(strict_types=1);

namespace Bot\Handler;

use Bot\Entity\SummarizationState\SummarizationStateRepository;
use Bot\Service\ChatService;
use Exception;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Bindings\Types\ReplyParameters;
use Phenogram\Framework\Handler\AbstractCommandHandler;
use Phenogram\Framework\TelegramBot;

class SummarizeCommandHandler extends AbstractCommandHandler
{
    private const COMMAND = '/wtf';

    public function __construct(
        private readonly ChatService $chatService,
        private readonly SummarizationStateRepository $summarizationStateRepository,
    ) {}

    public static function supports(UpdateInterface $update): bool
    {
        // Ensure it's a message and the command exists (potentially with arguments)
        return self::hasCommand($update, self::COMMAND);
    }

    public function handle(UpdateInterface $update, TelegramBot $bot): void
    {
        $message = $update->message;
        $chatId  = $message->chat->id;
        $userId  = $message->from->id;

        $bot->api->sendChatAction(
            chatId: $chatId,
            action: 'typing',
        );

        $question = explode(' ', $message->text, 2)[1] ?? null;

        $summary = $this->chatService->summarize($chatId, $userId, $message->replyToMessage?->messageId, $question);

        if ($summary === false) {
            $bot->api->sendMessage(
                chatId: $chatId,
                text: 'Не найдено достаточно сообщений для обработки, читайте сами.',
                replyParameters: new ReplyParameters(messageId: $message->messageId, allowSendingWithoutReply: true),
            );

            return;
        }

        // Determine which message to reply to
        $replyToMessageId = $message->messageId;
        
        // If this is a plain /wtf command with no question, reply to the last summarized message
        if ($question === null) {
            $lastSummarizedMessageId = $this->summarizationStateRepository->getLastSummarizedMessageId($chatId, $userId);
            if ($lastSummarizedMessageId !== null) {
                $replyToMessageId = $lastSummarizedMessageId;
            }
        }
        
        try {
            $bot->api->sendMessage(
                chatId: $chatId,
                text: $summary,
                parseMode: 'MarkdownV2',
                replyParameters: new ReplyParameters(messageId: $replyToMessageId, allowSendingWithoutReply: true),
            );
        } catch (Exception $e) {
            $bot->logger->error($e->getMessage());

            $bot->api->sendMessage(
                chatId: $chatId,
                text: $summary,
                replyParameters: new ReplyParameters(messageId: $replyToMessageId, allowSendingWithoutReply: true),
            );
        }
    }
}