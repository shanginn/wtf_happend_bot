<?php

declare(strict_types=1);

namespace Tests\Space\Persistence;

use Bot\Space\Persistence\SpaceBindingKey;
use Bot\Space\Persistence\SpaceMembershipStateStore;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

final class SpaceMembershipStateStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testAcceptedLeaveRetiresEverySpaceBoundToTheConversation(): void
    {
        $database       = Mockery::mock(DatabaseInterface::class);
        $stateStatement = Mockery::mock(StatementInterface::class);
        $spaceStatement = Mockery::mock(StatementInterface::class);

        $database->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (callable $callback): mixed => $callback($database));
        $database->shouldReceive('query')
            ->once()
            ->ordered()
            ->withArgs(static function (string $sql, array $parameters): bool {
                return str_contains($sql, 'INSERT INTO space_membership_states')
                    && str_contains($sql, 'space_membership_states.last_update_id < EXCLUDED.last_update_id')
                    && str_contains($sql, 'space_membership_states.last_update_id = EXCLUDED.last_update_id')
                    && $parameters === [
                        'primary-bot',
                        'telegram',
                        '-10042',
                        901,
                        'left',
                        false,
                        1_725_000_000,
                        1_725_000_000,
                    ];
            })
            ->andReturn($stateStatement);
        $stateStatement->shouldReceive('fetch')->once()->andReturn(['last_update_id' => 901]);
        $database->shouldReceive('query')
            ->once()
            ->ordered()
            ->withArgs(static function (string $sql, array $parameters): bool {
                return str_contains($sql, 'UPDATE agent_spaces AS space')
                    && str_contains($sql, 'dream_enabled = ?')
                    && str_contains($sql, 'binding.external_conversation_id = ?')
                    && str_contains($sql, "binding.external_thread_id = ''")
                    && $parameters === [
                        'retired',
                        false,
                        1_725_000_000,
                        'primary-bot',
                        'telegram',
                        '-10042',
                    ];
            })
            ->andReturn($spaceStatement);
        $spaceStatement->shouldReceive('fetchAll')->once()->andReturn([
            ['id' => 'spc_root_123'],
        ]);

        $result = (new SpaceMembershipStateStore($database))->apply(
            new SpaceBindingKey('primary-bot', 'telegram', '-10042'),
            updateId: 901,
            membershipStatus: 'left',
            active: false,
            eventAt: 1_725_000_000,
        );

        self::assertSame(['spc_root_123'], $result);
    }

    public function testStaleTransitionDoesNotTouchSpaces(): void
    {
        $database       = Mockery::mock(DatabaseInterface::class);
        $stateStatement = Mockery::mock(StatementInterface::class);

        $database->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (callable $callback): mixed => $callback($database));
        $database->shouldReceive('query')->once()->andReturn($stateStatement);
        $stateStatement->shouldReceive('fetch')->once()->andReturn(false);

        $result = (new SpaceMembershipStateStore($database))->apply(
            new SpaceBindingKey('primary-bot', 'telegram', '-10042'),
            updateId: 900,
            membershipStatus: 'member',
            active: true,
            eventAt: 1_724_000_000,
        );

        self::assertNull($result);
    }

    public function testRejectsTopicScopedMembershipTransition(): void
    {
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldNotReceive('transaction');

        $this->expectException(InvalidArgumentException::class);

        (new SpaceMembershipStateStore($database))->apply(
            new SpaceBindingKey('primary-bot', 'telegram', '-10042', '42'),
            updateId: 901,
            membershipStatus: 'left',
            active: false,
            eventAt: 1_725_000_000,
        );
    }
}
