<?php

declare(strict_types=1);

namespace Bot\Telegram;

use Phenogram\Bindings\Types\InaccessibleMessage;
use Phenogram\Bindings\Types\Interfaces\ChatInterface;
use Phenogram\Bindings\Types\Interfaces\MessageInterface;
use Phenogram\Bindings\Types\Interfaces\UserInterface;

class Update extends \Phenogram\Bindings\Types\Update
{
    private(set) ?UserInterface $effectiveUser {
        get {
            if (!isset($this->effectiveUser)) {
                $this->effectiveUser = $this->message?->from
                    ?? $this->editedMessage?->from
                    ?? $this->inlineQuery?->from
                    ?? $this->chosenInlineResult?->from
                    ?? $this->callbackQuery?->from
                    ?? $this->shippingQuery?->from
                    ?? $this->preCheckoutQuery?->from
                    ?? $this->pollAnswer?->user
                    ?? $this->myChatMember?->from
                    ?? $this->chatMember?->from
                    ?? $this->chatJoinRequest?->from
                    ?? $this->messageReaction?->user
                    ?? $this->businessMessage?->from
                    ?? $this->editedBusinessMessage?->from
                    ?? $this->businessConnection?->user
                    ?? $this->purchasedPaidMedia?->from;
            }

            return $this->effectiveUser;
        }
    }

    private(set) UserInterface|ChatInterface|null $effectiveSender {
        get {
            if (!isset($this->effectiveSender)) {
                $this->effectiveSender = (
                    $this->message ??
                    $this->editedMessage ??
                    $this->channelPost ??
                    $this->editedChannelPost ??
                    $this->businessMessage ??
                    $this->editedBusinessMessage
                )?->senderChat
                ?? $this->pollAnswer?->voterChat
                ?? $this->messageReaction?->actorChat
                ?? $this->effectiveUser
                ?? null;
            }

            return $this->effectiveSender;
        }
    }

    private(set) ?ChatInterface $effectiveChat {
        get {
            if (!isset($this->effectiveChat)) {
                $this->effectiveChat = $this->message?->chat
                    ?? $this->editedMessage?->chat
                    ?? $this->callbackQuery?->message?->chat
                    ?? $this->channelPost?->chat
                    ?? $this->editedChannelPost?->chat
                    ?? $this->myChatMember?->chat
                    ?? $this->chatMember?->chat
                    ?? $this->chatJoinRequest?->chat
                    ?? $this->chatBoost?->chat
                    ?? $this->removedChatBoost?->chat
                    ?? $this->messageReaction?->chat
                    ?? $this->messageReactionCount?->chat
                    ?? $this->businessMessage?->chat
                    ?? $this->editedBusinessMessage?->chat
                    ?? $this->deletedBusinessMessages?->chat
                    ?? null;
            }

            return $this->effectiveChat;
        }
    }

    private(set) ?MessageInterface $effectiveMessage {
        get {
            if (!isset($this->effectiveMessage)) {
                $message = $this->message
                    ?? $this->editedMessage
                    ?? $this->channelPost
                    ?? $this->editedChannelPost
                    ?? $this->businessMessage
                    ?? $this->editedBusinessMessage
                    ?? $this->callbackQuery?->message;

                if ($message !== null && !$message instanceof InaccessibleMessage) {
                    $this->effectiveMessage = $message;
                } else {
                    $this->effectiveMessage = null;
                }
            }

            return $this->effectiveMessage;
        }
    }

    public function getEffectiveUser(): ?UserInterface
    {
        return $this->effectiveUser;
    }

    public function getEffectiveSender(): UserInterface|ChatInterface|null
    {
        return $this->effectiveSender;
    }

    public function getEffectiveChat(): ?ChatInterface
    {
        return $this->effectiveChat;
    }

    public function getEffectiveMessage(): ?MessageInterface
    {
        return $this->effectiveMessage;
    }
}
