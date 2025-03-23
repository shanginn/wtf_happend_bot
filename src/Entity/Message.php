<?php

declare(strict_types=1);

namespace Bot\Entity;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use DateTimeImmutable;
use Phenogram\Bindings\Types\Interfaces\MessageInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;

#[Entity]
class Message
{
    #[Column(type: 'primary')]
    public int $id;

    public function __construct(
        // messageId
        #[Column(type: 'bigInteger')]
        public int $messageId,

        #[Column(type: 'text')]
        public string $text,

        #[Column(type: 'bigInteger')]
        public int $chatId,

        #[Column(type: 'bigInteger')]
        public int $date,

        #[Column(type: 'bigInteger')]
        public int $fromUserId,

        #[Column(type: 'text', nullable: true)]
        public ?string $fromUsername,
    ) {}

    public static function fromMessageUpdate(MessageInterface $message): self
    {
        return new self(
            messageId: $message->messageId,
            text: $message->text,
            chatId: $message->chat->id,
            date: $message->date,
            fromUserId: $message->from->id,
            fromUsername: $message->from->username,
        );
    }
}