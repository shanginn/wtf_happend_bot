<?php

declare(strict_types=1);

namespace Bot\Telegram;

use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\Types\Interfaces\ChatMemberInterface;
use UnexpectedValueException;

final class TelegramBindingsSerializer extends Serializer
{
    public function deserialize(mixed $data, string $type, bool $isArray = false): mixed
    {
        if ($type !== ChatMemberInterface::class) {
            return parent::deserialize($data, $type, $isArray);
        }

        if (!is_array($data)) {
            return $data;
        }

        if (!$isArray) {
            return $this->denormalizeChatMember($data);
        }

        $members = [];
        foreach ($data as $index => $item) {
            if (!is_array($item)) {
                throw new UnexpectedValueException(
                    sprintf('Chat member %s must be an object.', (string) $index),
                );
            }

            $members[] = $this->denormalizeChatMember($item);
        }

        return $members;
    }

    public function supports(string $type): bool
    {
        return $type === ChatMemberInterface::class || parent::supports($type);
    }
}
