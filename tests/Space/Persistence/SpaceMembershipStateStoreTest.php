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
                    && str_contains($sql, 'space_membership_states.event_at < EXCLUDED.event_at')
                    && str_contains($sql, 'space_membership_states.event_at = EXCLUDED.event_at')
                    && str_contains($sql, 'space_membership_states.last_update_id < EXCLUDED.last_update_id')
                    && $parameters === [
                        'primary-bot',
                        'telegram',
                        '-10042',
                        901,
                        'left',
                        0,
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
                        0,
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

    public function testOrderingUsesEventTimeThenUpdateIdAndOnlyAcceptsAnExactReplay(): void
    {
        $database       = Mockery::mock(DatabaseInterface::class);
        $stateStatement = Mockery::mock(StatementInterface::class);
        $membershipSql  = null;

        $database->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (callable $callback): mixed => $callback($database));
        $database->shouldReceive('query')
            ->once()
            ->withArgs(static function (string $sql, array $parameters) use (&$membershipSql): bool {
                $membershipSql = $sql;

                return $parameters === [
                    'primary-bot',
                    'telegram',
                    '-10042',
                    1,
                    'member',
                    1,
                    1_725_000_000,
                    1_725_000_000,
                ];
            })
            ->andReturn($stateStatement);
        $stateStatement->shouldReceive('fetch')->once()->andReturn(false);

        $result = (new SpaceMembershipStateStore($database))->apply(
            new SpaceBindingKey('primary-bot', 'telegram', '-10042'),
            updateId: 1,
            membershipStatus: 'member',
            active: true,
            eventAt: 1_725_000_000,
        );

        self::assertNull($result);
        self::assertIsString($membershipSql);
        self::assertMatchesRegularExpression(
            '/space_membership_states\.event_at\s*<\s*EXCLUDED\.event_at'
            . '\s+OR\s*\(\s*space_membership_states\.event_at\s*=\s*EXCLUDED\.event_at'
            . '\s+AND\s+space_membership_states\.last_update_id\s*<\s*EXCLUDED\.last_update_id\s*\)'
            . '\s+OR\s*\(\s*space_membership_states\.event_at\s*=\s*EXCLUDED\.event_at'
            . '\s+AND\s+space_membership_states\.last_update_id\s*=\s*EXCLUDED\.last_update_id'
            . '\s+AND\s+space_membership_states\.membership_status\s*=\s*EXCLUDED\.membership_status'
            . '\s+AND\s+space_membership_states\.active\s*=\s*EXCLUDED\.active\s*\)/s',
            $membershipSql,
        );
        self::assertStringContainsString(
            'updated_at = GREATEST(',
            $membershipSql,
        );
        self::assertStringNotContainsString(
            "WHERE\n                    space_membership_states.last_update_id < EXCLUDED.last_update_id",
            $membershipSql,
        );
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
