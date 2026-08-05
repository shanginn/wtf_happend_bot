<?php

declare(strict_types=1);

namespace Bot\Telegram;

use Phenogram\Bindings\ApiInterface;
use Phenogram\Bindings\Types\Interfaces\ChatMemberAdministratorInterface;
use Phenogram\Bindings\Types\Interfaces\ChatMemberInterface;
use Phenogram\Bindings\Types\Interfaces\ChatMemberOwnerInterface;
use Phenogram\Bindings\Types\Interfaces\MessageInterface;

final readonly class TelegramChatAuthorizationPolicy
{
    public function __construct(
        private ApiInterface $api,
    ) {}

    public function isMessageActorAuthorized(MessageInterface $message): bool
    {
        if ($message->from === null || $message->senderChat !== null) {
            return false;
        }

        return $this->areActorsAuthorized(
            chatId: $message->chat->id,
            chatType: $message->chat->type,
            actorUserIds: [$message->from->id],
        );
    }

    /**
     * @param list<mixed> $actorUserIds
     * @param int         $chatId
     * @param string      $chatType
     */
    public function areActorsAuthorized(
        int $chatId,
        string $chatType,
        array $actorUserIds,
    ): bool {
        if ($chatId === 0) {
            return false;
        }

        $actorUserIds = $this->validatedActorUserIds($actorUserIds);
        if ($actorUserIds === null) {
            return false;
        }

        if ($chatType === 'private') {
            return $chatId > 0
                && count($actorUserIds) === 1
                && $actorUserIds[0] === $chatId;
        }

        if (!in_array($chatType, ['group', 'supergroup', 'channel'], true)) {
            return false;
        }

        foreach ($actorUserIds as $actorUserId) {
            $member = $this->api->getChatMember($chatId, $actorUserId);
            if (!$this->isPrivilegedMember($member)) {
                return false;
            }
        }

        return true;
    }

    private function isPrivilegedMember(
        ChatMemberInterface $member,
    ): bool {
        if ($member instanceof ChatMemberOwnerInterface) {
            return $member->status === 'creator' && !$member->isAnonymous;
        }

        return $member instanceof ChatMemberAdministratorInterface
            && $member->status === 'administrator'
            && !$member->isAnonymous;
    }

    /**
     * @param list<mixed> $actorUserIds
     *
     * @return non-empty-list<int>|null
     */
    private function validatedActorUserIds(array $actorUserIds): ?array
    {
        if ($actorUserIds === [] || !array_is_list($actorUserIds)) {
            return null;
        }

        $validated = [];
        foreach ($actorUserIds as $actorUserId) {
            if (!is_int($actorUserId) || $actorUserId <= 0) {
                return null;
            }

            $validated[$actorUserId] = $actorUserId;
        }

        return array_values($validated);
    }
}
