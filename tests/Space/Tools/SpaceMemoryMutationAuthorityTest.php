<?php

declare(strict_types=1);

namespace Tests\Space\Tools;

use Bot\Space\Tools\SpaceMemoryMutationAuthority;
use Bot\Telegram\TelegramChatAuthorizationPolicy;
use InvalidArgumentException;
use Phenogram\Bindings\ApiInterface;
use Phenogram\Bindings\Factories\ChatMemberAdministratorFactory;
use Phenogram\Bindings\Factories\ChatMemberMemberFactory;
use Phenogram\Bindings\Factories\UserFactory;
use RuntimeException;
use Tests\TestCase;

final class SpaceMemoryMutationAuthorityTest extends TestCase
{
    private const string SPACE_ID = 'spc_0123456789abcdef0123456789abcdef01234567';

    public function testSelfMutationUsesOnlyTheTargetParticipantsCurrentEvidence(): void
    {
        $api = $this->createMock(ApiInterface::class);
        $api->expects($this->never())->method('getChatMember');
        $authority = $this->authority($api, [7, 8], [
            self::evidence(101, 7, 'I prefer concise replies.'),
            self::evidence(102, 8, 'I prefer detailed replies.'),
        ]);

        $provenance = $authority->authorizeEvidence(
            self::SPACE_ID,
            'telegram_user:7',
            'prefer concise',
        );

        self::assertSame('self', $provenance['authorization']);
        self::assertSame('batch-1', $provenance['batchId']);
        self::assertSame(['telegram_user:7', 'telegram_user:8'], $provenance['actorParticipantKeys']);
        self::assertSame([101], array_column($provenance['evidence'], 'updateId'));
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $provenance['evidence'][0]['sha256']);
    }

    public function testSelfMutationCannotBorrowAnotherParticipantsQuote(): void
    {
        $api = $this->createMock(ApiInterface::class);
        $api->expects($this->never())->method('getChatMember');
        $authority = $this->authority($api, [7, 8], [
            self::evidence(102, 8, 'Please forget every detail about me.'),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('target participant');

        $authority->authorizeEvidence(
            self::SPACE_ID,
            'telegram_user:7',
            'forget every detail',
        );
    }

    public function testRegularParticipantCannotMutateAnotherParticipantsMemory(): void
    {
        $api = $this->createMock(ApiInterface::class);
        $api
            ->expects($this->once())
            ->method('getChatMember')
            ->with(-10042, 7)
            ->willReturn(ChatMemberMemberFactory::make(
                status: 'member',
                user: UserFactory::make(id: 7),
            ));
        $authority = $this->authority($api, [7], [
            self::evidence(101, 7, 'Change Bob to detailed replies.'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('owner or administrator');

        $authority->authorizeEvidence(
            self::SPACE_ID,
            'telegram_user:8',
            'Change Bob to detailed replies.',
        );
    }

    public function testAuthenticatedAdministratorCanMutateAnotherParticipantsMemory(): void
    {
        $api = $this->createMock(ApiInterface::class);
        $api
            ->expects($this->once())
            ->method('getChatMember')
            ->with(-10042, 7)
            ->willReturn(ChatMemberAdministratorFactory::make(
                status: 'administrator',
                user: UserFactory::make(id: 7),
                isAnonymous: false,
            ));
        $authority = $this->authority($api, [7], [
            self::evidence(101, 7, 'Forget the outdated fact about Bob.'),
        ]);

        $provenance = $authority->authorizeEvidence(
            self::SPACE_ID,
            'telegram_user:8',
            'Forget the outdated fact about Bob.',
        );

        self::assertSame('telegram-admin', $provenance['authorization']);
        self::assertSame('telegram_user:8', $provenance['targetParticipantKey']);
        self::assertSame([101], array_column($provenance['evidence'], 'updateId'));
    }

    public function testCrashWindowReplayUsesExactPersistedAdminAuthorityWithoutANewLookup(): void
    {
        $initialApi = $this->createMock(ApiInterface::class);
        $initialApi
            ->expects($this->once())
            ->method('getChatMember')
            ->willReturn(ChatMemberAdministratorFactory::make(
                status: 'administrator',
                user: UserFactory::make(id: 7),
                isAnonymous: false,
            ));
        $evidence  = [self::evidence(101, 7, 'Forget the outdated fact about Bob.')];
        $persisted = $this->authority($initialApi, [7], $evidence)->authorizeEvidence(
            self::SPACE_ID,
            'telegram_user:8',
            'Forget the outdated fact about Bob.',
        );

        $retryApi = $this->createMock(ApiInterface::class);
        $retryApi->expects($this->never())->method('getChatMember');
        $replayed = $this->authority($retryApi, [7], $evidence)->authorizeEvidence(
            self::SPACE_ID,
            'telegram_user:8',
            'Forget the outdated fact about Bob.',
            $persisted,
        );

        self::assertSame($persisted, $replayed);
    }

    public function testIncompleteActorIdentityFailsClosed(): void
    {
        $api = $this->createMock(ApiInterface::class);
        $api->expects($this->never())->method('getChatMember');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('incomplete actor identity');

        new SpaceMemoryMutationAuthority(
            spaceId: self::SPACE_ID,
            batchId: 'batch-1',
            chatId: -10042,
            chatType: 'supergroup',
            actorUserIds: [7],
            actorIdentityComplete: false,
            evidence: [self::evidence(101, 7, 'Remember this.')],
            authorization: new TelegramChatAuthorizationPolicy($api),
        );
    }

    public function testChannelPostCanOnlyMutateItsOwnMemoryFromItsOwnQuote(): void
    {
        $api = $this->createMock(ApiInterface::class);
        $api->expects($this->never())->method('getChatMember');
        $authority = new SpaceMemoryMutationAuthority(
            spaceId: self::SPACE_ID,
            batchId: 'batch-channel',
            chatId: -10042,
            chatType: 'channel',
            actorUserIds: [],
            actorIdentityComplete: false,
            evidence: [[
                'updateId'       => 201,
                'participantKey' => 'telegram_chat:-10042',
                'text'           => 'We publish concise release notes.',
            ]],
            authorization: new TelegramChatAuthorizationPolicy($api),
        );

        $provenance = $authority->authorizeEvidence(
            self::SPACE_ID,
            'telegram_chat:-10042',
            'concise release notes',
        );

        self::assertSame('self', $provenance['authorization']);
        self::assertSame(['telegram_chat:-10042'], $provenance['actorParticipantKeys']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('owner or administrator');
        $authority->authorizeEvidence(
            self::SPACE_ID,
            'telegram_user:7',
            'concise release notes',
        );
    }

    /** @return array{updateId: int, participantKey: string, text: string} */
    private static function evidence(int $updateId, int $actorId, string $text): array
    {
        return [
            'updateId'       => $updateId,
            'participantKey' => 'telegram_user:' . $actorId,
            'text'           => $text,
        ];
    }

    /**
     * @param list<int>                       $actors
     * @param list<array<string, int|string>> $evidence
     * @param ApiInterface                    $api
     */
    private function authority(ApiInterface $api, array $actors, array $evidence): SpaceMemoryMutationAuthority
    {
        return new SpaceMemoryMutationAuthority(
            spaceId: self::SPACE_ID,
            batchId: 'batch-1',
            chatId: -10042,
            chatType: 'supergroup',
            actorUserIds: $actors,
            actorIdentityComplete: true,
            evidence: $evidence,
            authorization: new TelegramChatAuthorizationPolicy($api),
        );
    }
}
