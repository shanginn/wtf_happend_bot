<?php

declare(strict_types=1);

namespace Tests\Space\Tools;

use Bot\Space\Persistence\SpaceMemoryStore;
use Bot\Space\Persistence\SpaceStore;
use Bot\Space\Tools\SpaceMemoryMutationAuthority;
use Bot\Space\Tools\SpaceMemoryToolStore;
use Bot\Telegram\TelegramChatAuthorizationPolicy;
use Cycle\Database\DatabaseInterface;
use Cycle\ORM\ORMInterface;
use InvalidArgumentException;
use Mockery;
use Phenogram\Bindings\ApiInterface;
use Tests\TestCase;

final class SpaceMemoryToolStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testLiveSaveUsesTheHostPrivacyGateBeforePersistence(): void
    {
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldNotReceive('query');
        $database->shouldNotReceive('execute');
        $database->shouldNotReceive('transaction');
        $store = new SpaceMemoryToolStore(new SpaceMemoryStore(
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            $database,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed private data');

        $store->save(
            spaceId: 'spc_0123456789abcdef0123456789abcdef01234567',
            userIdentifier: 'telegram_user:42',
            memory: 'The participant uses private@example.com.',
            quote: 'Email me there.',
            context: 'Contact preference.',
            idempotencyKey: 'tool-call-1',
            authority: self::authority('Email me there.'),
        );
    }

    private static function authority(string $evidence): SpaceMemoryMutationAuthority
    {
        return new SpaceMemoryMutationAuthority(
            spaceId: 'spc_0123456789abcdef0123456789abcdef01234567',
            batchId: 'batch-1',
            chatId: -10042,
            chatType: 'supergroup',
            actorUserIds: [42],
            actorIdentityComplete: true,
            evidence: [[
                'updateId'       => 101,
                'participantKey' => 'telegram_user:42',
                'text'           => $evidence,
            ]],
            authorization: new TelegramChatAuthorizationPolicy(Mockery::mock(ApiInterface::class)),
        );
    }
}
