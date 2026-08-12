<?php

declare(strict_types=1);

namespace Tests\Space\Operations;

use Bot\Space\Operations\HostReleaseStateStore;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class HostReleaseStateStoreAbortFenceTest extends TestCase
{
    private const string ACTIVE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string CANDIDATE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testAbortRestoresOldActiveGateAndAppendsPermanentFence(): void
    {
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('transaction')->once()->andReturnUsing(
            static fn (callable $callback): mixed => $callback($database),
        );
        $database->shouldReceive('query')->once()->andReturn(self::statement([]));
        $database->shouldReceive('query')->once()->andReturn(self::statement([
            'desired_release_id' => self::CANDIDATE,
            'active_release_id' => self::ACTIVE,
            'phase' => 'prepared',
            'generation' => 2,
        ]));
        $database->shouldReceive('execute')->once()->withArgs(
            static fn (string $sql, array $parameters): bool =>
                str_contains($sql, 'INSERT INTO host_release_abortions')
                && $parameters === [self::CANDIDATE, 123, 2],
        )->andReturn(1);
        $database->shouldReceive('execute')->once()->withArgs(
            static fn (string $sql, array $parameters): bool =>
                str_contains($sql, 'desired_release_id = active_release_id')
                && $parameters === [self::CANDIDATE, 123, self::CANDIDATE],
        )->andReturn(1);

        $states = new HostReleaseStateStore($database);
        self::assertSame('aborted', $states->abortPrepared(self::CANDIDATE, 123));

        $database->shouldReceive('query')->once()->andReturn(self::statement(['aborted' => true]));
        self::assertSame('aborted', $states->status(self::CANDIDATE));
        $database->shouldReceive('query')->once()->andReturn(self::statement([
            'desired_release_id' => self::ACTIVE,
            'active_release_id' => self::ACTIVE,
            'phase' => 'active',
        ]));
        self::assertTrue($states->isActive(self::ACTIVE));
    }

    public function testOlderAbortedDigestCannotBePreparedAfterAnotherAbort(): void
    {
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('transaction')->once()->andReturnUsing(
            static fn (callable $callback): mixed => $callback($database),
        );
        $database->shouldReceive('query')->once()->andReturn(self::statement([]));
        // This row models A aborted, then B aborted: the denormalized last
        // pointer is B, while append-only history still contains A.
        $database->shouldReceive('query')->once()->withArgs(
            static fn (string $sql, array $parameters): bool =>
                str_contains($sql, 'host_release_abortions')
                && $parameters === [self::CANDIDATE],
        )->andReturn(self::statement(['aborted' => true]));
        $database->shouldNotReceive('execute');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('durably aborted');
        (new HostReleaseStateStore($database))->prepare(self::CANDIDATE, 124);
    }

    public function testSuccessorCannotTombstoneTheLastKnownGoodActiveRelease(): void
    {
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('transaction')->once()->andReturnUsing(
            static fn (callable $callback): mixed => $callback($database),
        );
        $database->shouldReceive('query')->once()->andReturn(self::statement([]));
        $database->shouldReceive('query')->once()->andReturn(self::statement([
            'desired_release_id' => self::CANDIDATE,
            'active_release_id' => self::ACTIVE,
            'phase' => 'prepared',
            'generation' => 3,
        ]));
        $database->shouldNotReceive('execute');

        self::assertSame(
            'retired',
            (new HostReleaseStateStore($database))->abortPrepared(self::ACTIVE, 125),
        );
    }

    /** @param array<string, mixed> $row */
    private static function statement(array $row): StatementInterface
    {
        $statement = Mockery::mock(StatementInterface::class);
        $statement->shouldReceive('fetch')->once()->andReturn($row);

        return $statement;
    }
}
