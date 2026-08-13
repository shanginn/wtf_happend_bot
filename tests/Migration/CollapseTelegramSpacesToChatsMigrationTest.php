<?php

declare(strict_types=1);

namespace Tests\Migration;

require_once dirname(__DIR__, 2)
    . '/migrations/20260813.140000_0_0_default_collapse_telegram_spaces_to_chats.php';

use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Cycle\Migrations\CapsuleInterface;
use Migration\OrmDefaultCollapseTelegramSpacesToChats20260813140000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CollapseTelegramSpacesToChatsMigrationTest extends TestCase
{
    private const CANONICAL_TOPIC_PATHS = [
        '{effective_message,is_topic_message}',
        '{message,is_topic_message}',
        '{edited_message,is_topic_message}',
        '{channel_post,is_topic_message}',
        '{edited_channel_post,is_topic_message}',
    ];

    /**
     * @return iterable<string, array{
     *     array{non_root_count: string, cleanup_count: string, dependent_count: string},
     *     string
     * }>
     */
    public static function failClosedGuardProvider(): iterable
    {
        yield 'genuine topic evidence' => [
            [
                'non_root_count'  => '3323',
                'cleanup_count'   => '3322',
                'dependent_count' => '0',
            ],
            'cannot collapse Telegram Spaces: 3323 non-root bindings, 3322 verified legacy artifacts',
        ];

        yield 'durable dependent' => [
            [
                'non_root_count'  => '3323',
                'cleanup_count'   => '3323',
                'dependent_count' => '1',
            ],
            'cannot collapse Telegram Spaces: verified legacy artifacts have 1 durable dependents',
        ];
    }

    /**
     * @param array{non_root_count: string, cleanup_count: string, dependent_count: string} $guard
     */
    #[DataProvider('failClosedGuardProvider')]
    public function testFailClosedGuardThrowsAfterFetchingCounts(
        array $guard,
        string $expectedMessage,
    ): void {
        $statement = $this->createMock(StatementInterface::class);
        $statement->expects(self::once())
            ->method('fetch')
            ->willReturn($guard);

        $executedSql = [];
        $database    = $this->createMock(DatabaseInterface::class);
        $database->expects(self::exactly(2))
            ->method('execute')
            ->willReturnCallback(static function (string $sql) use (&$executedSql): int {
                $executedSql[] = $sql;

                return 0;
            });
        $database->expects(self::once())
            ->method('query')
            ->willReturn($statement);

        $capsule = $this->createMock(CapsuleInterface::class);
        $capsule->expects(self::exactly(3))
            ->method('getDatabase')
            ->willReturn($database);

        $migration = (new OrmDefaultCollapseTelegramSpacesToChats20260813140000())
            ->withCapsule($capsule);

        try {
            $migration->up();
            self::fail('Expected the corrective migration to fail closed');
        } catch (RuntimeException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }

        self::assertCount(2, $executedSql);
        self::assertStringContainsString('CREATE TEMPORARY TABLE', $executedSql[0]);
        self::assertStringContainsString('INSERT INTO legacy_thread_space_cleanup', $executedSql[1]);
        self::assertStringNotContainsString('RAISE EXCEPTION', implode("\n", $executedSql));

        foreach (self::CANONICAL_TOPIC_PATHS as $topicPath) {
            self::assertStringContainsString($topicPath, $executedSql[1]);
        }
    }

    public function testCleanupPreservesTopicsAndEnforcesChatScopedInvariants(): void
    {
        $guardStatement = $this->createMock(StatementInterface::class);
        $guardStatement->expects(self::once())
            ->method('fetch')
            ->willReturn([
                'non_root_count'  => '0',
                'cleanup_count'   => '0',
                'dependent_count' => '0',
            ]);

        $remainingStatement = $this->createMock(StatementInterface::class);
        $remainingStatement->expects(self::once())
            ->method('fetchColumn')
            ->willReturn('0');

        $executedSql = [];
        $queriedSql  = [];
        $queryResult = [$guardStatement, $remainingStatement];
        $database    = $this->createMock(DatabaseInterface::class);
        $database->expects(self::exactly(10))
            ->method('execute')
            ->willReturnCallback(static function (string $sql) use (&$executedSql): int {
                $executedSql[] = $sql;

                return 0;
            });
        $database->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(
                static function (string $sql) use (&$queriedSql, &$queryResult): StatementInterface {
                    $queriedSql[] = $sql;

                    return array_shift($queryResult);
                },
            );

        $capsule = $this->createMock(CapsuleInterface::class);
        $capsule->expects(self::exactly(12))
            ->method('getDatabase')
            ->willReturn($database);

        (new OrmDefaultCollapseTelegramSpacesToChats20260813140000())
            ->withCapsule($capsule)
            ->up();

        $candidateSql = $executedSql[1];
        self::assertStringContainsString("release.created_by = 'legacy-import'", $candidateSql);
        self::assertStringContainsString('release.sequence = 1', $candidateSql);
        self::assertStringContainsString('release.parent_release_id IS NULL', $candidateSql);
        self::assertStringContainsString('release.source_proposal_id IS NULL', $candidateSql);

        $normalizationSql = $executedSql[2];
        self::assertStringContainsString('UPDATE update_records', $normalizationSql);
        foreach (self::CANONICAL_TOPIC_PATHS as $topicPath) {
            self::assertStringContainsString($topicPath, $normalizationSql);
        }

        $allSql = implode("\n", [...$executedSql, ...$queriedSql]);
        foreach ([
            'space_skill_versions',
            'space_memory_versions',
            'space_dream_runs',
            'space_upgrade_proposals',
            'space_promotion_events',
            'space_sandbox_jobs',
            'space_runtime_snapshots',
        ] as $dependentTable) {
            self::assertStringContainsString($dependentTable, $allSql);
        }
        self::assertStringContainsString(
            'ADD CONSTRAINT space_bindings_telegram_chat_scoped',
            $allSql,
        );
        self::assertStringContainsString(
            "CHECK (platform <> 'telegram' OR external_thread_id = '')",
            $allSql,
        );
        self::assertStringContainsString(
            'CREATE INDEX update_records_index_chat_id_created_at',
            $allSql,
        );
    }
}
