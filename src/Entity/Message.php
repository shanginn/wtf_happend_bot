<?php

declare(strict_types=1);

namespace Bot\Entity;

use Bot\Entity\Message\MessageRepository;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Phenogram\Bindings\Types\Interfaces\MessageInterface;

#[Entity(repository: MessageRepository::class)]
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
        #[Column(type: 'text', nullable: true)]
        public ?string $fileId = null,
    ) {}

    public static function fromMessageUpdate(MessageInterface $message): self
    {
        $text   = $message->text ?? '';
        $fileId = null;

        // Handle photos with captions
        if ($message->photo !== null) {
            // Get the last photo which is the highest resolution one
            $photo  = end($message->photo);
            $fileId = $photo->fileId;

            // Use caption as text if available
            if ($message->caption !== null) {
                $text = $message->caption;
            }
        }

        // Handle documents
        if ($message->document !== null) {
            $fileId = $message->document->fileId;

            // Use caption as text if available
            if ($message->caption !== null) {
                $text = $message->caption;
            }
        }

        return new self(
            messageId: $message->messageId,
            text: $text,
            chatId: $message->chat->id,
            date: $message->date,
            fromUserId: $message->from->id,
            fromUsername: $message->from->username,
            fileId: $fileId,
        );
    }
}