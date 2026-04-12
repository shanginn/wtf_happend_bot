<?php

declare(strict_types=1);

namespace Bot\Activity;

use Bot\Entity\Message as ChatMessage;
use Bot\Entity\UpdateRecord;
use Bot\Telegram\InputMessageView;
use Bot\Telegram\TelegramUpdateViewFactory;
use Bot\Telegram\Update;
use Carbon\CarbonInterval;
use Cycle\ORM\EntityManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Phenogram\Bindings\ApiInterface;
use Phenogram\Bindings\ResponseException;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\SerializerInterface;
use Phenogram\Bindings\Types\InlineKeyboardMarkup;
use Phenogram\Bindings\Types\Interfaces\InputFileInterface;
use Phenogram\Bindings\Types\Interfaces\ReplyParametersInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Bindings\Types\LinkPreviewOptions;
use Phenogram\Bindings\Types\Message;
use Phenogram\Bindings\Types\ReplyParameters;
use Phenogram\Bindings\Types\Interfaces\FileInterface;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;

#[ActivityInterface(prefix: 'Telegram.')]
class TelegramActivity
{
    private const int BOT_USER_ID = 777777;

    private SerializerInterface $serializer;
    private TelegramUpdateViewFactory $updateViewFactory;

    public function __construct(
        private ApiInterface $api,
        private ORMInterface $orm,
        private EntityManagerInterface $em,
    ) {
        $this->serializer = new Serializer();
        $this->updateViewFactory = new TelegramUpdateViewFactory();
    }

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

    #[ActivityMethod]
    public function saveMessage(
        int $chatId,
        string $text,
        int $messageId,
    ): void {
        $message = new ChatMessage(
            messageId: $messageId,
            text: $text,
            chatId: $chatId,
            date: time(),
            fromUserId: self::BOT_USER_ID,
            fromUsername: 'bot',
        );

        /** @var \Bot\Entity\Message\MessageRepository $repo */
        $repo = $this->orm->getRepository(ChatMessage::class);
        $repo->save($message, run: true);
    }

    #[ActivityMethod]
    public function saveUpdates(Update $update): true
    {
        $chatId = $update->effectiveChat?->id;

        if ($chatId === null) {
            return true;
        }

        /** @var \Bot\Entity\UpdateRecord\UpdateRecordRepository $repo */
        $repo = $this->orm->getRepository(UpdateRecord::class);

        // Skip if update already exists
        if ($repo->exists($update->updateId)) {
            return true;
        }

        $encoded = json_encode($this->serializer->serialize([$update])[0]);

        $record = new UpdateRecord(
            updateId: $update->updateId,
            update: $encoded,
            chatId: $chatId,
            topicId: $update->effectiveMessage?->messageThreadId,
            createdAt: $update->effectiveMessage?->date ?? time(),
        );

        $this->em->persist($record);
        $this->em->run();

        return true;
    }

    #[ActivityMethod]
    public function sendPoll(
        int|string $chatId,
        string $question,
        array $options,
        ?int $messageThreadId = null,
        bool $isAnonymous = true,
        bool $allowsMultipleAnswers = false,
    ): int|string {
        $inputOptions = array_map(
            fn(string $text) => new \Phenogram\Bindings\Types\InputPollOption(text: $text),
            $options
        );

        /** @var Message $message */
        $message = $this->api->sendPoll(
            chatId: $chatId,
            question: $question,
            options: $inputOptions,
            messageThreadId: $messageThreadId,
            isAnonymous: $isAnonymous,
            allowsMultipleAnswers: $allowsMultipleAnswers,
        );

        return $message->messageId;
    }

    #[ActivityMethod]
    public function updateToView(UpdateInterface $update): InputMessageView
    {
        return $this->updateViewFactory->create($update);
    }
}
