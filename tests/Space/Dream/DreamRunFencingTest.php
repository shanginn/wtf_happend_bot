<?php

declare(strict_types=1);

namespace Tests\Space\Dream;

use Bot\Space\Dream\DreamActivities;
use Bot\Space\Dream\SpaceDreamInput;
use Bot\Space\Persistence\SpaceStore;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Cycle\ORM\ORMInterface;
use Mockery;
use PiPHP\Temporal\Contract\ModelCompletionGatewayInterface;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class DreamRunFencingTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testInitialClaimPersistsAndReturnsGenerationOne(): void
    {
        [$activities, $database] = self::activities();
        self::expectActivation($database);
        self::expectTransaction($database);
        $database->shouldReceive('execute')->once()->andReturn(1);
        $row = self::statement([
            ...self::runRow('run-1', 'chain-1', 1, 1),
            'baseline_release_id' => 'release-1',
        ]);
        $database->shouldReceive('query')->once()->andReturn($row);

        $claimed = $activities->claimDreamRun(self::boundInput('run-1', 'chain-1', 1));

        self::assertSame(1, $claimed->executionGeneration);
        self::assertSame('release-1', $claimed->baselineReleaseId);
    }

    public function testHigherAttemptInSameTemporalChainAtomicallyReplacesTokenAndGeneration(): void
    {
        [$activities, $database] = self::activities();
        self::expectActivation($database);
        self::expectTransaction($database);
        $database->shouldReceive('execute')->once()->andReturn(0);
        $database->shouldReceive('query')->once()->andReturn(self::statement([
            ...self::runRow('run-old', 'chain-1', 1, 4),
            'baseline_release_id' => 'release-1',
        ]));
        $database->shouldReceive('execute')->once()->withArgs(
            static fn (string $sql, array $values): bool => str_contains($sql, 'execution_generation = ?')
                && $values[1] === 'run-new'
                && $values[2] === 'chain-1'
                && $values[3] === 2
                && $values[4] === 5
                && $values[8] === 'run-old'
                && $values[9] === 4,
        )->andReturn(1);

        $claimed = $activities->claimDreamRun(self::boundInput('run-new', 'chain-1', 2));

        self::assertSame('run-new', $claimed->executionToken);
        self::assertSame(5, $claimed->executionGeneration);
    }

    public function testSameRunClaimRetryReusesGenerationWithoutReset(): void
    {
        [$activities, $database] = self::activities();
        self::expectActivation($database);
        self::expectTransaction($database);
        $database->shouldReceive('execute')->once()->andReturn(0);
        $database->shouldReceive('query')->once()->andReturn(self::statement([
            ...self::runRow('run-1', 'chain-1', 1, 7),
            'baseline_release_id' => 'release-1',
        ]));

        $claimed = $activities->claimDreamRun(self::boundInput('run-1', 'chain-1', 1));

        self::assertSame(7, $claimed->executionGeneration);
    }

    public function testOlderDifferentChainCannotReclaimFreshSuccessorEvenWhenItsAttemptIsHigher(): void
    {
        [$activities, $database] = self::activities();
        self::expectActivation($database);
        self::expectTransaction($database);
        $database->shouldReceive('execute')->once()->andReturn(0);
        $database->shouldReceive('query')->once()->andReturn(self::statement([
            ...self::runRow('run-successor', 'chain-new', 1, 8),
            'baseline_release_id' => 'release-1',
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stale or the nightly run is already terminal');
        $activities->claimDreamRun(self::boundInput('run-zombie', 'chain-old', 99));
    }

    public function testLateSameTokenFinishCannotOverwriteFailure(): void
    {
        [$activities, $database] = self::activities();
        $input                   = self::boundInput('run-1', 'chain-1', 1)->claim('release-1', 3);
        $database->shouldReceive('execute')->once()->withArgs(
            static fn (string $sql, array $values): bool => str_contains($sql, "status = 'failed'")
                && str_contains($sql, "status = 'running'")
                && $values[4] === 'run-1'
                && $values[5] === 3,
        )->andReturn(1);
        $activities->recordFailure($input, 'worker failed', 'sha256:' . str_repeat('f', 64));

        $database->shouldReceive('execute')->once()->withArgs(
            static fn (string $sql, array $values): bool => str_contains($sql, "status = 'running'")
                && $values[5] === 'run-1'
                && $values[6] === 3,
        )->andReturn(0);
        $database->shouldReceive('query')->once()->andReturn(self::statement([
            'status'       => 'failed',
            'summary_json' => '{"outcome":"failed"}',
        ]));
        $database->shouldNotReceive('execute')->withArgs(
            static fn (string $sql): bool => str_contains($sql, 'UPDATE agent_spaces'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lease changed before terminal persistence');
        (new ReflectionMethod(DreamActivities::class, 'finishDream'))->invoke(
            $activities,
            $input,
            'noop',
            ['reason' => 'late zombie'],
        );
    }

    public function testIdenticalTerminalRetryIsAcknowledgedWithoutRewritingTheRun(): void
    {
        [$activities, $database] = self::activities();
        $input                   = self::boundInput('run-1', 'chain-1', 1)->claim('release-1', 3);
        $summary                 = '{"outcome":"noop","reason":"nothing changed"}';
        $database->shouldReceive('execute')->once()->withArgs(
            static fn (string $sql): bool => str_contains($sql, "status = 'running'"),
        )->andReturn(0);
        $database->shouldReceive('query')->once()->andReturn(self::statement([
            'status'       => 'noop',
            'summary_json' => $summary,
        ]));
        $database->shouldReceive('execute')->once()->withArgs(
            static fn (string $sql): bool => str_contains($sql, 'UPDATE agent_spaces'),
        )->andReturn(1);

        (new ReflectionMethod(DreamActivities::class, 'finishDream'))->invoke(
            $activities,
            $input,
            'noop',
            ['reason' => 'nothing changed'],
        );

        self::addToAssertionCount(1);
    }

    /** @return array{DreamActivities, DatabaseInterface&Mockery\MockInterface} */
    private static function activities(): array
    {
        $database = Mockery::mock(DatabaseInterface::class);

        return [
            new DreamActivities(
                $database,
                new SpaceStore(Mockery::mock(ORMInterface::class), $database),
                Mockery::mock(ModelCompletionGatewayInterface::class),
            ),
            $database,
        ];
    }

    private static function expectActivation(DatabaseInterface $database): void
    {
        $database->shouldReceive('query')->once()->andReturn(self::statement([
            'id'                 => self::spaceId(),
            'active_release_id'  => 'release-1',
            'release_generation' => 1,
            'memory_revision'    => 0,
        ]));
    }

    private static function expectTransaction(DatabaseInterface $database): void
    {
        $database->shouldReceive('transaction')->once()->andReturnUsing(
            static fn (callable $callback): mixed => $callback($database),
        );
    }

    /** @param array<string, mixed> $row */
    private static function statement(array $row): StatementInterface
    {
        $statement = Mockery::mock(StatementInterface::class);
        $statement->shouldReceive('fetch')->once()->andReturn($row);

        return $statement;
    }

    /** @return array<string, mixed> */
    private static function runRow(
        string $token,
        string $chainToken,
        int $attempt,
        int $generation,
    ): array {
        return [
            'id'                    => self::dreamRunId(),
            'space_id'              => self::spaceId(),
            'dream_date'            => '2026-08-12',
            'status'                => 'running',
            'execution_token'       => $token,
            'execution_chain_token' => $chainToken,
            'execution_attempt'     => $attempt,
            'execution_generation'  => $generation,
            'heartbeat_at'          => time(),
        ];
    }

    private static function boundInput(string $token, string $chainToken, int $attempt): SpaceDreamInput
    {
        return new SpaceDreamInput(
            spaceId: self::spaceId(),
            dreamDate: '2026-08-12',
            executionToken: $token,
            executionChainToken: $chainToken,
            executionAttempt: $attempt,
        );
    }

    private static function spaceId(): string
    {
        return 'spc_0123456789abcdef0123456789abcdef01234567';
    }

    private static function dreamRunId(): string
    {
        return 'eb15b40b-1aa5-4a1f-a9c5-d4a41ef2d585';
    }
}
