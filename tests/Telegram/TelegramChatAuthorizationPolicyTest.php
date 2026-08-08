<?php

declare(strict_types=1);

namespace Tests\Telegram;

use Bot\Telegram\TelegramChatAuthorizationPolicy;
use Phenogram\Bindings\ApiInterface;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\ChatMemberAdministratorFactory;
use Phenogram\Bindings\Factories\ChatMemberMemberFactory;
use Phenogram\Bindings\Factories\ChatMemberOwnerFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\UserFactory;
use Tests\TestCase;

final class TelegramChatAuthorizationPolicyTest extends TestCase
{
    public function testPrivateChatRequiresEveryActorToBeTheActualChatUser(): void
    {
        $api = $this->createMock(ApiInterface::class);
        $api->expects($this->never())->method('getChatMember');
        $authorization = new TelegramChatAuthorizationPolicy($api);

        self::assertTrue($authorization->areActorsAuthorized(42, 'private', [42, 42]));
        self::assertFalse($authorization->areActorsAuthorized(42, 'private', [42, 43]));
        self::assertFalse($authorization->areActorsAuthorized(42, 'private', []));
        self::assertFalse($authorization->areActorsAuthorized(42, 'private', [2 => 42]));
    }

    public function testEveryGroupActorMustResolveAsNonAnonymousOwnerOrAdministrator(): void
    {
        $owner = ChatMemberOwnerFactory::make(
            status: 'creator',
            user: UserFactory::make(id: 11),
            isAnonymous: false,
        );
        $administrator = ChatMemberAdministratorFactory::make(
            status: 'administrator',
            user: UserFactory::make(id: 12),
            isAnonymous: false,
        );
        $api = $this->createMock(ApiInterface::class);
        $api
            ->expects($this->exactly(2))
            ->method('getChatMember')
            ->willReturnCallback(static fn(int $chatId, int $userId) => match ([$chatId, $userId]) {
                [-10042, 11] => $owner,
                [-10042, 12] => $administrator,
            });

        self::assertTrue((new TelegramChatAuthorizationPolicy($api))->areActorsAuthorized(
            -10042,
            'supergroup',
            [11, 12],
        ));
    }

    public function testRegularOrAnonymousGroupMembersAreDenied(): void
    {
        $regularApi = $this->createMock(ApiInterface::class);
        $regularApi
            ->expects($this->once())
            ->method('getChatMember')
            ->willReturn(ChatMemberMemberFactory::make(
                status: 'member',
                user: UserFactory::make(id: 11),
            ));
        self::assertFalse((new TelegramChatAuthorizationPolicy($regularApi))->areActorsAuthorized(
            -10042,
            'group',
            [11],
        ));

        $anonymousApi = $this->createMock(ApiInterface::class);
        $anonymousApi
            ->expects($this->once())
            ->method('getChatMember')
            ->willReturn(ChatMemberAdministratorFactory::make(
                status: 'administrator',
                user: UserFactory::make(id: 11),
                isAnonymous: true,
            ));
        self::assertFalse((new TelegramChatAuthorizationPolicy($anonymousApi))->areActorsAuthorized(
            -10042,
            'channel',
            [11],
        ));
    }

    public function testChatAttributedMessageFailsClosedWithoutTelegramLookup(): void
    {
        $api = $this->createMock(ApiInterface::class);
        $api->expects($this->never())->method('getChatMember');
        $message = MessageFactory::make(
            chat: ChatFactory::make(id: -10042, type: 'supergroup'),
            from: UserFactory::make(id: 11),
            senderChat: ChatFactory::make(id: -10042, type: 'supergroup'),
        );

        self::assertFalse(
            (new TelegramChatAuthorizationPolicy($api))->isMessageActorAuthorized($message),
        );
    }
}
