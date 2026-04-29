<?php

declare(strict_types=1);

namespace Bot\Telegram;

use Phenogram\Bindings\Factory as BaseFactory;
use Phenogram\Bindings\Types\Interfaces\BusinessConnectionInterface;
use Phenogram\Bindings\Types\Interfaces\BusinessMessagesDeletedInterface;
use Phenogram\Bindings\Types\Interfaces\CallbackQueryInterface;
use Phenogram\Bindings\Types\Interfaces\ChatBoostRemovedInterface;
use Phenogram\Bindings\Types\Interfaces\ChatBoostUpdatedInterface;
use Phenogram\Bindings\Types\Interfaces\ChatJoinRequestInterface;
use Phenogram\Bindings\Types\Interfaces\ChatMemberUpdatedInterface;
use Phenogram\Bindings\Types\Interfaces\ChosenInlineResultInterface;
use Phenogram\Bindings\Types\Interfaces\InlineQueryInterface;
use Phenogram\Bindings\Types\Interfaces\ManagedBotUpdatedInterface;
use Phenogram\Bindings\Types\Interfaces\MessageInterface;
use Phenogram\Bindings\Types\Interfaces\MessageReactionCountUpdatedInterface;
use Phenogram\Bindings\Types\Interfaces\MessageReactionUpdatedInterface;
use Phenogram\Bindings\Types\Interfaces\PaidMediaPurchasedInterface;
use Phenogram\Bindings\Types\Interfaces\PollAnswerInterface;
use Phenogram\Bindings\Types\Interfaces\PollInterface;
use Phenogram\Bindings\Types\Interfaces\PreCheckoutQueryInterface;
use Phenogram\Bindings\Types\Interfaces\ShippingQueryInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Bindings\Types\Update;

class Factory extends BaseFactory
{
    public function makeUpdate(
        int $updateId,
        ?MessageInterface $message,
        ?MessageInterface $editedMessage,
        ?MessageInterface $channelPost,
        ?MessageInterface $editedChannelPost,
        ?BusinessConnectionInterface $businessConnection,
        ?MessageInterface $businessMessage,
        ?MessageInterface $editedBusinessMessage,
        ?BusinessMessagesDeletedInterface $deletedBusinessMessages,
        ?MessageReactionUpdatedInterface $messageReaction,
        ?MessageReactionCountUpdatedInterface $messageReactionCount,
        ?InlineQueryInterface $inlineQuery,
        ?ChosenInlineResultInterface $chosenInlineResult,
        ?CallbackQueryInterface $callbackQuery,
        ?ShippingQueryInterface $shippingQuery,
        ?PreCheckoutQueryInterface $preCheckoutQuery,
        ?PaidMediaPurchasedInterface $purchasedPaidMedia,
        ?PollInterface $poll,
        ?PollAnswerInterface $pollAnswer,
        ?ChatMemberUpdatedInterface $myChatMember,
        ?ChatMemberUpdatedInterface $chatMember,
        ?ChatJoinRequestInterface $chatJoinRequest,
        ?ChatBoostUpdatedInterface $chatBoost,
        ?ChatBoostRemovedInterface $removedChatBoost,
        ?ManagedBotUpdatedInterface $managedBot,
    ): UpdateInterface {
        return new Update(
            updateId: $updateId,
            message: $message,
            editedMessage: $editedMessage,
            channelPost: $channelPost,
            editedChannelPost: $editedChannelPost,
            businessConnection: $businessConnection,
            businessMessage: $businessMessage,
            editedBusinessMessage: $editedBusinessMessage,
            deletedBusinessMessages: $deletedBusinessMessages,
            messageReaction: $messageReaction,
            messageReactionCount: $messageReactionCount,
            inlineQuery: $inlineQuery,
            chosenInlineResult: $chosenInlineResult,
            callbackQuery: $callbackQuery,
            shippingQuery: $shippingQuery,
            preCheckoutQuery: $preCheckoutQuery,
            purchasedPaidMedia: $purchasedPaidMedia,
            poll: $poll,
            pollAnswer: $pollAnswer,
            myChatMember: $myChatMember,
            chatMember: $chatMember,
            chatJoinRequest: $chatJoinRequest,
            chatBoost: $chatBoost,
            removedChatBoost: $removedChatBoost,
            managedBot: $managedBot,
        );
    }
}
