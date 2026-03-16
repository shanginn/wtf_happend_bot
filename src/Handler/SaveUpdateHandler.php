<?php

declare(strict_types=1);

namespace Bot\Handler;

use Bot\Entity\Message;
use Bot\Entity\UpdateRecord;
use Cycle\ORM\EntityManagerInterface;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Framework\Handler\UpdateHandlerInterface;
use Phenogram\Framework\TelegramBot;

class SaveUpdateHandler implements UpdateHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public static function supports(UpdateInterface $update): bool
    {
        return $update->message !== null && (
            $update->message->text !== null
            || $update->message->photo !== null
            || $update->message->document !== null
        );
    }

    public function handle(UpdateInterface $update, TelegramBot $bot)
    {
        $this->em->persist(Message::fromMessageUpdate($update->message));

        $serializer =
        $serialized = $serializer->serialize([$update])[0];

        $updateRecord = new UpdateRecord(
            updateId: $update->updateId,
            update: $serialized,
            chatId: $update->message->chat->id,
        );
        $this->em->persist($updateRecord);

        $this->em->run();
        $this->em->clean();
    }
}