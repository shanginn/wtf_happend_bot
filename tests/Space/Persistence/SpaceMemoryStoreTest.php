<?php

declare(strict_types=1);

namespace Tests\Space\Persistence;

use Bot\Entity\SpaceMemoryVersion;
use Bot\Space\Persistence\SpaceBindingKey;
use Bot\Space\Persistence\SpaceId;
use Bot\Space\Persistence\SpaceMemoryStore;
use Bot\Space\Persistence\SpaceStore;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Cycle\ORM\ORMInterface;
use Mockery;
use PDO;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class SpaceMemoryStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testPinnedRecallReconstructsVersionThatWasSupersededLater(): void
    {
        $spaceId = SpaceId::forBinding(new SpaceBindingKey(
            'default',
            'telegram',
            '-1001',
        ));
        $database  = Mockery::mock(DatabaseInterface::class);
        $statement = Mockery::mock(StatementInterface::class);
        $database
            ->shouldReceive('query')
            ->once()
            ->withArgs(static fn (string $sql, array $parameters): bool => str_contains($sql, "current.status <> 'forgotten'")
                && str_contains($sql, "THEN 'active'")
                && str_contains($sql, 'newer.revision <= ?')
                && $parameters === [$spaceId, 1, 1, 20])
            ->andReturn($statement);
        $statement->shouldReceive('fetchAll')->once()->andReturn([self::memoryRow(
            spaceId: $spaceId,
            status: SpaceMemoryVersion::STATUS_SUPERSEDED,
            effectiveStatus: SpaceMemoryVersion::STATUS_ACTIVE,
        )]);

        $records = (new SpaceMemoryStore(
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            $database,
        ))->recall($spaceId, atRevision: 1);

        self::assertCount(1, $records);
        self::assertSame('memory-1', $records[0]->id);
        self::assertSame(SpaceMemoryVersion::STATUS_ACTIVE, $records[0]->status);
    }

    public function testHistoricalRecallKeepsDreamForgetHiddenUntilAppendOnlyRestore(): void
    {
        $database = new PDO('sqlite::memory:');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->exec(<<<'SQL'
            CREATE TABLE space_memory_versions (
                id TEXT PRIMARY KEY,
                space_id TEXT NOT NULL,
                revision INTEGER NOT NULL,
                participant_key TEXT NOT NULL,
                status TEXT NOT NULL,
                supersedes_memory_id TEXT NULL
            )
            SQL);
        $insert = $database->prepare(<<<'SQL'
            INSERT INTO space_memory_versions (
                id, space_id, revision, participant_key, status, supersedes_memory_id
            ) VALUES (?, ?, ?, ?, ?, ?)
            SQL);
        $spaceId = 'spc_0123456789abcdef0123456789abcdef01234567';
        $insert->execute(['baseline', $spaceId, 1, 'telegram_user:7', 'superseded', null]);
        $insert->execute(['dream-forget', $spaceId, 2, 'telegram_user:7', 'forgotten', 'baseline']);
        $insert->execute(['rollback-restore', $spaceId, 3, 'telegram_user:7', 'active', 'dream-forget']);

        $method = new ReflectionMethod(SpaceMemoryStore::class, 'historicalRecallSql');
        $sql    = $method->invoke(null, '');

        self::assertSame(['baseline'], self::historicalIds($database, $sql, $spaceId, 1));
        self::assertSame([], self::historicalIds($database, $sql, $spaceId, 2));
        self::assertSame(['rollback-restore'], self::historicalIds($database, $sql, $spaceId, 3));
        self::assertSame(
            'forgotten',
            $database->query("SELECT status FROM space_memory_versions WHERE id = 'dream-forget'")
                ->fetchColumn(),
        );
    }

    public function testIdempotencyPrefixLookupIsSpaceScopedAndOrdered(): void
    {
        $spaceId = SpaceId::forBinding(new SpaceBindingKey(
            'default',
            'telegram',
            '-1002',
        ));
        $prefix    = 'forget-all:batch:';
        $database  = Mockery::mock(DatabaseInterface::class);
        $statement = Mockery::mock(StatementInterface::class);
        $database
            ->shouldReceive('query')
            ->once()
            ->withArgs(static fn (string $sql, array $parameters): bool => str_contains(
                $sql,
                'left(idempotency_key, length(?)) = ?',
            )
                && str_contains($sql, 'ORDER BY revision ASC')
                && $parameters === [$spaceId, $prefix, $prefix])
            ->andReturn($statement);
        $statement->shouldReceive('fetchAll')->once()->andReturn([array_replace(
            self::memoryRow(
                spaceId: $spaceId,
                status: SpaceMemoryVersion::STATUS_FORGOTTEN,
                effectiveStatus: SpaceMemoryVersion::STATUS_FORGOTTEN,
            ),
            [
                'idempotency_key'      => $prefix . 'memory-original',
                'supersedes_memory_id' => 'memory-original',
            ],
        )]);
        $store = new SpaceMemoryStore(
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            $database,
        );

        $records = $store->byIdempotencyPrefix($spaceId, $prefix);

        self::assertCount(1, $records);
        self::assertSame($prefix . 'memory-original', $records[0]->idempotencyKey);
        self::assertSame('memory-original', $records[0]->supersedesMemoryId);
    }

    public function testMutationFailsBeforeSelectingATargetWhenPinnedRevisionIsStale(): void
    {
        $spaceId = SpaceId::forBinding(new SpaceBindingKey(
            'default',
            'telegram',
            '-1003',
        ));
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (callable $callback): mixed => $callback($database));
        $spaceStatement = Mockery::mock(StatementInterface::class);
        $database->shouldReceive('query')
            ->once()
            ->withArgs(static fn (string $sql, array $parameters): bool => str_contains(
                $sql,
                'SELECT memory_revision',
            ) && $parameters === [$spaceId])
            ->andReturn($spaceStatement);
        $spaceStatement->shouldReceive('fetch')->once()->andReturn(['memory_revision' => 8]);
        $database->shouldNotReceive('execute');

        $store = new SpaceMemoryStore(
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            $database,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pinned Space memory revision is stale');

        $store->update(
            spaceId: $spaceId,
            memoryId: 'memory-1',
            participantKey: 'telegram_user:42',
            participantLabel: 'telegram_user:42',
            memory: 'Prefers detailed replies.',
            quote: 'Please explain it.',
            context: 'Answer style.',
            expectedMemoryRevision: 7,
        );
    }

    public function testAtomicForgetValidatesEveryTargetBeforeWriting(): void
    {
        $spaceId = SpaceId::forBinding(new SpaceBindingKey(
            'default',
            'telegram',
            '-1004',
        ));
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (callable $callback): mixed => $callback($database));

        $spaceStatement = Mockery::mock(StatementInterface::class);
        $database->shouldReceive('query')
            ->once()
            ->withArgs(static fn (string $sql, array $parameters): bool => str_contains(
                $sql,
                'SELECT memory_revision',
            ) && $parameters === [$spaceId])
            ->andReturn($spaceStatement);
        $spaceStatement->shouldReceive('fetch')->once()->andReturn(['memory_revision' => 2]);

        foreach (['forget:memory-1', 'forget:memory-2'] as $key) {
            $statement = Mockery::mock(StatementInterface::class);
            $database->shouldReceive('query')
                ->once()
                ->withArgs(static fn (string $sql, array $parameters): bool => str_contains(
                    $sql,
                    'idempotency_key = ?',
                ) && $parameters === [$spaceId, $key])
                ->andReturn($statement);
            $statement->shouldReceive('fetch')->once()->andReturn(false);
        }

        $firstTarget = Mockery::mock(StatementInterface::class);
        $database->shouldReceive('query')
            ->once()
            ->withArgs(static fn (string $sql, array $parameters): bool => str_contains(
                $sql,
                "status = 'active'",
            ) && $parameters === ['memory-1', $spaceId])
            ->andReturn($firstTarget);
        $firstTarget->shouldReceive('fetch')->once()->andReturn(self::memoryRow(
            spaceId: $spaceId,
            status: SpaceMemoryVersion::STATUS_ACTIVE,
            effectiveStatus: SpaceMemoryVersion::STATUS_ACTIVE,
        ));

        $missingTarget = Mockery::mock(StatementInterface::class);
        $database->shouldReceive('query')
            ->once()
            ->withArgs(static fn (string $sql, array $parameters): bool => str_contains(
                $sql,
                "status = 'active'",
            ) && $parameters === ['memory-2', $spaceId])
            ->andReturn($missingTarget);
        $missingTarget->shouldReceive('fetch')->once()->andReturn(false);
        $database->shouldNotReceive('execute');

        $store = new SpaceMemoryStore(
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            $database,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('selected for atomic forget is stale');

        $store->forgetMany($spaceId, [
            [
                'memoryId'       => 'memory-1',
                'provenanceJson' => '{"source":"test"}',
                'idempotencyKey' => 'forget:memory-1',
            ],
            [
                'memoryId'       => 'memory-2',
                'provenanceJson' => '{"source":"test"}',
                'idempotencyKey' => 'forget:memory-2',
            ],
        ], expectedMemoryRevision: 2);
    }

    /**
     * @param string $spaceId
     * @param string $status
     * @param string $effectiveStatus
     *
     * @return array<string, mixed>
     */
    private static function memoryRow(
        string $spaceId,
        string $status,
        string $effectiveStatus,
    ): array {
        return [
            'id'                   => 'memory-1',
            'space_id'             => $spaceId,
            'revision'             => 1,
            'participant_key'      => 'telegram_user:42',
            'participant_label'    => 'telegram_user:42',
            'memory'               => 'Prefers short replies.',
            'quote'                => 'Keep it short.',
            'context'              => 'Answer style.',
            'status'               => $status,
            'effective_status'     => $effectiveStatus,
            'idempotency_key'      => 'save:1',
            'supersedes_memory_id' => null,
            'provenance_json'      => '{}',
            'confidence_permille'  => null,
            'created_at'           => 100,
            'source_updated_at'    => 100,
        ];
    }

    /** @return list<string> */
    private static function historicalIds(PDO $database, string $sql, string $spaceId, int $revision): array
    {
        $statement = $database->prepare($sql);
        $statement->execute([$spaceId, $revision, $revision, 20]);

        return array_values(array_map(
            static fn (array $row): string => (string) $row['id'],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        ));
    }
}
