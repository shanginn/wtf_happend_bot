<?php

declare(strict_types=1);

namespace Tests\Telegram;

use Bot\Telegram\TelegramBindingsSerializer;
use Phenogram\Bindings\Types\Interfaces\ChatMemberAdministratorInterface;
use Phenogram\Bindings\Types\Interfaces\ChatMemberInterface;
use PHPUnit\Framework\TestCase;

final class TelegramBindingsSerializerTest extends TestCase
{
    public function testGenericChatMemberInterfaceUsesStatusDiscriminator(): void
    {
        $serializer = new TelegramBindingsSerializer();

        self::assertTrue($serializer->supports(ChatMemberInterface::class));

        $data = [
            'status'                  => 'administrator',
            'user'                    => [
                'id'         => 11,
                'is_bot'     => false,
                'first_name' => 'Admin',
            ],
            'can_be_edited'           => false,
            'is_anonymous'            => false,
            'can_manage_chat'         => true,
            'can_delete_messages'     => true,
            'can_manage_video_chats'  => true,
            'can_restrict_members'    => true,
            'can_promote_members'     => true,
            'can_change_info'         => true,
            'can_invite_users'        => true,
            'can_post_stories'        => true,
            'can_edit_stories'        => true,
            'can_delete_stories'      => true,
        ];
        $member = $serializer->deserialize($data, ChatMemberInterface::class);

        self::assertInstanceOf(ChatMemberAdministratorInterface::class, $member);
        self::assertSame(11, $member->user->id);

        $members = $serializer->deserialize([$data], ChatMemberInterface::class, isArray: true);
        self::assertIsArray($members);
        self::assertCount(1, $members);
        self::assertInstanceOf(ChatMemberAdministratorInterface::class, $members[0]);
    }
}
