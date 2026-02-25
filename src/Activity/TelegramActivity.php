<?php

declare(strict_types=1);

namespace Bot\Activity;

use Carbon\CarbonInterval;
use Phenogram\Bindings\ApiInterface;
use Phenogram\Bindings\ResponseException;
use Phenogram\Bindings\Types\InlineKeyboardMarkup;
use Phenogram\Bindings\Types\Interfaces\InputFileInterface;
use Phenogram\Bindings\Types\Interfaces\ReplyParametersInterface;
use Phenogram\Bindings\Types\LinkPreviewOptions;
use Phenogram\Bindings\Types\Message;
use Phenogram\Bindings\Types\ReplyParameters;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;

#[ActivityInterface(prefix: 'Telegram.')]
class TelegramActivity
{
    public function __construct(
        private ApiInterface $api
    ) {}

    public static function getDefinition(): ActivityProxy|self
    {
        return Workflow::newActivityStub(
            self::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(CarbonInterval::minutes(1))
                ->withRetryOptions(
                    RetryOptions::new()->withNonRetryableExceptions([])
                )
                ->withRetryOptions(
                    RetryOptions::new()
                        ->withBackoffCoefficient(2.5)
                        ->withInitialInterval(20)
                )
        );
    }

    #[ActivityMethod]
    public function sendMessage(
        int|string $chatId,
        string $text,
        ?InlineKeyboardMarkup $inlineReplyMarkup = null,
        ?int $messageThreadId = null,
        ?string $parseMode = null,
        ?array $entities = null,
        ?LinkPreviewOptions $linkPreviewOptions = null,
        ?bool $disableNotification = null,
        ?bool $protectContent = null,
        ?ReplyParameters $replyParameters = null,
    ): int|string {
        /** @var Message $message */
        $message = $this->api->sendMessage(
            chatId: $chatId,
            text: $text,
            messageThreadId: $messageThreadId,
            parseMode: $parseMode,
            entities: $entities,
            linkPreviewOptions: $linkPreviewOptions,
            disableNotification: $disableNotification,
            protectContent: $protectContent,
            replyParameters: $replyParameters,
            replyMarkup: $inlineReplyMarkup,
        );

        return $message->messageId;
    }

    #[ActivityMethod]
    public function sendChatAction(
        int|string $chatId,
        string $action,
        ?int $messageThreadId = null,
    ): true {
        $this->api->sendChatAction(
            chatId: $chatId,
            action: $action,
            messageThreadId: $messageThreadId,
        );

        return true;
    }

    #[ActivityMethod]
    public function editMessageText(
        string $text,
        null|int|string $chatId = null,
        ?int $messageId = null,
        ?InlineKeyboardMarkup $replyMarkup = null,
        ?string $inlineMessageId = null,
        ?string $parseMode = null,
        ?array $entities = null,
        ?LinkPreviewOptions $linkPreviewOptions = null,
    ): true {
        try {
            $this->api->editMessageText(
                text: $text,
                chatId: $chatId,
                messageId: $messageId,
                inlineMessageId: $inlineMessageId,
                parseMode: $parseMode,
                entities: $entities,
                linkPreviewOptions: $linkPreviewOptions,
                replyMarkup: $replyMarkup,
            );
        } catch (ResponseException $e) {
            if (!str_contains($e->getMessage(), 'message to edit not found')) {
                throw $e;
            }

            $this->api->sendMessage(
                chatId: $chatId,
                text: $text,
                replyMarkup: $replyMarkup,
                parseMode: $parseMode,
                entities: $entities,
                linkPreviewOptions: $linkPreviewOptions,
            );
        }

        return true;
    }
}
