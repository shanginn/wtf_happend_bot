<?php

declare(strict_types=1);

namespace Tests\Space\Publication;

use Bot\Infrastructure\CycleORM\TrueAsyncPostgresDriver;
use Bot\Space\Persistence\SpaceReleaseSeed;
use Bot\Space\Persistence\SqlBoolean;
use Bot\Space\Publication\SpaceCapabilityPublicationInput;
use Bot\Space\Publication\SpaceCapabilityPublicationRejected;
use Bot\Space\Publication\SpaceCapabilityPublisher;
use Bot\Space\Runtime\SpaceCapabilityPolicy;
use Bot\Space\Runtime\SpaceRuntimeSnapshot;
use Bot\Space\Runtime\SpaceRuntimeSnapshotLoaderActivity;
use Bot\Space\Runtime\SpaceRuntimeSnapshotRequest;
use Bot\Space\Tools\SpaceToolCatalog;
use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\Postgres\TcpConnectionConfig;
use Cycle\Database\Config\PostgresDriverConfig;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use PDO;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Real transaction/locking coverage. Opt in with SPACE_PUBLICATION_PG_HOST;
 * the test creates and drops only one random schema in the selected database.
 */
final class SpaceCapabilityPublisherPostgresTest extends TestCase
{
    private const string SPACE_ID = 'spc_0123456789abcdef0123456789abcdef01234567';

    private DatabaseInterface $database;
    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('SPACE_PUBLICATION_PG_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('Set SPACE_PUBLICATION_PG_HOST to run the real PostgreSQL publication tests.');
        }

        $this->database = self::connectDatabase();
        $this->schema   = 'space_publication_' . bin2hex(random_bytes(8));
        $this->database->execute('CREATE SCHEMA "' . $this->schema . '"');
        $this->database->execute('SET search_path TO "' . $this->schema . '"');
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->database, $this->schema)) {
            $this->database->execute('SET search_path TO public');
            $this->database->execute('DROP SCHEMA "' . $this->schema . '" CASCADE');
        }

        parent::tearDown();
    }

    public function testPublishesSkillAndCommandThenReplaysAfterLaterRollback(): void
    {
        $initial   = $this->seedSpace();
        $publisher = new SpaceCapabilityPublisher($this->database);
        $skill     = self::input(
            snapshot: $initial,
            batchId: 'batch_initial',
            terminalScopeId: 'terminal:skill',
            invocationKey: 'call:skill',
            kind: SpaceCapabilityPublicationInput::KIND_SKILL,
            name: 'concise-replies',
            description: 'Отвечает кратко на простой вопрос.',
            instructions: 'Сразу дай короткий полезный ответ без вступления.',
        );

        $skillResult = $publisher->publish($skill, 1_000);

        self::assertFalse($skillResult->replayed);
        self::assertSame(2, $skillResult->releaseGeneration);
        self::assertSame(
            [$skillResult->releaseId, 2],
            array_values($this->row(
                'SELECT active_release_id, release_generation FROM agent_spaces WHERE id = ?',
                [self::SPACE_ID],
            )),
        );
        self::assertSame('retired', $this->value(
            'SELECT status FROM space_releases WHERE id = ?',
            [$initial->releaseId],
        ));
        $skillRelease = $this->row('SELECT * FROM space_releases WHERE id = ?', [$skillResult->releaseId]);
        self::assertSame($initial->releaseId, $skillRelease['parent_release_id']);
        self::assertNull($skillRelease['source_proposal_id']);
        self::assertSame('active', $skillRelease['status']);
        self::assertSame('model-test', $skillRelease['model']);
        self::assertSame('Отвечай по делу.', $skillRelease['prompt']);
        self::assertSame('{"tone":"dry"}', $skillRelease['personality_json']);
        self::assertSame(SpaceCapabilityPolicy::JSON, $skillRelease['capability_policy_json']);
        $manifest = self::decode((string) $skillRelease['manifest_json']);
        self::assertSame(['keep' => true], $manifest['futureExtension']);
        self::assertSame(1, $this->value(
            'SELECT COUNT(*) FROM space_skill_versions WHERE release_id = ? AND name = ?',
            [$skillResult->releaseId, 'concise-replies'],
        ));
        self::assertSame(
            [$initial->releaseId, $skillResult->releaseId, 'promote', 1, 2, 'telegram_user:42'],
            array_values($this->row(
                'SELECT from_release_id, to_release_id, action, release_generation_before, '
                . 'release_generation_after, actor FROM space_promotion_events',
            )),
        );

        $sameBatch = $this->snapshot('batch_initial');
        self::assertSame($initial->snapshotId, $sameBatch->snapshotId);
        self::assertSame($initial->releaseId, $sameBatch->releaseId);

        $afterSkill = $this->snapshot('batch_after_skill');
        self::assertSame($skillResult->releaseId, $afterSkill->releaseId);
        self::assertStringContainsString('### concise-replies', $afterSkill->systemPrompt);
        self::assertStringContainsString($skill->instructions, $afterSkill->systemPrompt);

        $command = self::input(
            snapshot: $afterSkill,
            batchId: 'batch_after_skill',
            terminalScopeId: 'terminal:command',
            invocationKey: 'call:command',
            kind: SpaceCapabilityPublicationInput::KIND_COMMAND,
            name: 'punish',
            description: 'Шуточно наказывает бота за последнее неудачное сообщение.',
            instructions: 'Опиши одновременный разряд, удар и ледяную воду как шуточное наказание.',
        );
        $commandResult = $publisher->publish($command, 2_000);

        self::assertFalse($commandResult->replayed);
        self::assertSame(3, $commandResult->releaseGeneration);
        self::assertSame($skillResult->releaseId, $commandResult->sourceReleaseId);
        self::assertSame(1, $this->value(
            'SELECT COUNT(*) FROM space_skill_versions WHERE release_id = ? AND name = ?',
            [$commandResult->releaseId, 'concise-replies'],
        ));

        $afterCommand = $this->snapshot('batch_after_command');
        self::assertCount(1, $afterCommand->commands);
        self::assertSame('punish', $afterCommand->commands[0]->name);
        self::assertSame($command->instructions, $afterCommand->commands[0]->instructions);

        $releaseCount = $this->value('SELECT COUNT(*) FROM space_releases');

        try {
            $publisher->publish(self::input(
                snapshot: $afterCommand,
                batchId: 'batch_after_command',
                terminalScopeId: 'terminal:reserved',
                invocationKey: 'call:reserved',
                kind: SpaceCapabilityPublicationInput::KIND_COMMAND,
                name: 'clear',
                description: 'Переопределяет служебную команду.',
                instructions: 'Попытайся очистить состояние через пользовательскую команду.',
            ));
            self::fail('A reserved host command must not be published.');
        } catch (SpaceCapabilityPublicationRejected $error) {
            self::assertStringContainsString('reserved by the host', $error->getMessage());
        }
        self::assertSame($releaseCount, $this->value('SELECT COUNT(*) FROM space_releases'));
        self::assertSame(3, $this->value(
            'SELECT release_generation FROM agent_spaces WHERE id = ?',
            [self::SPACE_ID],
        ));

        $this->database->transaction(function (DatabaseInterface $database) use (
            $skillResult,
            $commandResult,
        ): void {
            $database->execute(
                "UPDATE space_releases SET status = 'retired' WHERE id = ?",
                [$commandResult->releaseId],
            );
            $database->execute(
                "UPDATE space_releases SET status = 'active' WHERE id = ?",
                [$skillResult->releaseId],
            );
            $database->execute(
                'UPDATE agent_spaces SET active_release_id = ?, release_generation = 4 WHERE id = ?',
                [$skillResult->releaseId, self::SPACE_ID],
            );
        });

        self::assertSame(
            $command->authorizationProvenance,
            $publisher->persistedAuthority(
                self::SPACE_ID,
                $command->terminalScopeId,
                $command->invocationKey,
            ),
        );
        $replay = $publisher->publish($command, 3_000);
        self::assertTrue($replay->replayed);
        self::assertSame($commandResult->releaseId, $replay->releaseId);
        self::assertSame(4, $this->value(
            'SELECT release_generation FROM agent_spaces WHERE id = ?',
            [self::SPACE_ID],
        ));

        try {
            $publisher->publish(self::input(
                snapshot: $afterSkill,
                batchId: 'batch_after_skill',
                terminalScopeId: $command->terminalScopeId,
                invocationKey: $command->invocationKey,
                kind: SpaceCapabilityPublicationInput::KIND_COMMAND,
                name: 'punish',
                description: 'Другая команда с тем же ключом.',
                instructions: 'Это другой payload и он не должен переиспользовать публикацию.',
            ));
            self::fail('A reused invocation key with different content must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('another request', $error->getMessage());
        }

        try {
            $publisher->publish(self::input(
                snapshot: $afterSkill,
                batchId: 'batch_after_skill',
                terminalScopeId: 'terminal:stale',
                invocationKey: 'call:stale',
                kind: SpaceCapabilityPublicationInput::KIND_SKILL,
                name: 'stale-skill',
                description: 'Пытается публиковаться из старого поколения.',
                instructions: 'Этот запрос должен потребовать новый batch.',
            ));
            self::fail('A stale pinned snapshot must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('stale', $error->getMessage());
        }
        self::assertSame($releaseCount, $this->value('SELECT COUNT(*) FROM space_releases'));
    }

    public function testSkillCountBudgetRollsBackTheWholePublication(): void
    {
        $skills = [];
        foreach (range(1, 20) as $index) {
            $skills[] = [
                'name'        => sprintf('skill_%02d', $index),
                'description' => "Existing skill {$index}.",
                'body'        => "Apply existing skill {$index}.",
                'enabled'     => true,
            ];
        }
        $snapshot  = $this->seedSpace($skills);
        $publisher = new SpaceCapabilityPublisher($this->database);

        try {
            $publisher->publish(self::input(
                snapshot: $snapshot,
                batchId: 'batch_initial',
                terminalScopeId: 'terminal:budget',
                invocationKey: 'call:budget',
                kind: SpaceCapabilityPublicationInput::KIND_SKILL,
                name: 'skill_21',
                description: 'One skill too many.',
                instructions: 'This entire transaction must roll back.',
            ));
            self::fail('The twenty-first skill must fail the release budget.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('more than 20 skills', $error->getMessage());
        }

        self::assertSame(1, $this->value('SELECT COUNT(*) FROM space_releases'));
        self::assertSame(0, $this->value('SELECT COUNT(*) FROM space_promotion_events'));
        self::assertSame(
            [$snapshot->releaseId, 1],
            array_values($this->row(
                'SELECT active_release_id, release_generation FROM agent_spaces WHERE id = ?',
                [self::SPACE_ID],
            )),
        );
    }

    public function testConcurrentSameInvocationPublishesExactlyOnceAndReturnsReplay(): void
    {
        $snapshot = $this->seedSpace();
        $this->delayActiveReleaseCas();
        $input = self::input(
            snapshot: $snapshot,
            batchId: 'batch_initial',
            terminalScopeId: 'terminal:concurrent-same',
            invocationKey: 'call:concurrent-same',
            kind: SpaceCapabilityPublicationInput::KIND_COMMAND,
            name: 'punish',
            description: 'Шуточно наказывает бота за последнее неудачное сообщение.',
            instructions: 'Опиши разряд, удар и ледяную воду как шуточное наказание.',
        );

        $results = $this->runConcurrent([$input, $input]);

        self::assertSame(['ok', 'ok'], array_column($results, 'status'));
        $replayed = array_values(array_unique(array_column($results, 'replayed'), \SORT_REGULAR));
        sort($replayed, \SORT_REGULAR);
        self::assertSame([false, true], $replayed);
        self::assertCount(1, array_unique(array_column($results, 'releaseId')));
        self::assertSame(2, $this->value('SELECT COUNT(*) FROM space_releases'));
        self::assertSame(1, $this->value('SELECT COUNT(*) FROM space_promotion_events'));
        self::assertSame(2, $this->value(
            'SELECT release_generation FROM agent_spaces WHERE id = ?',
            [self::SPACE_ID],
        ));
    }

    public function testConcurrentDifferentInvocationsHaveOneWinnerAndOneStaleRequest(): void
    {
        $snapshot = $this->seedSpace();
        $this->delayActiveReleaseCas();
        $first = self::input(
            snapshot: $snapshot,
            batchId: 'batch_initial',
            terminalScopeId: 'terminal:concurrent-different',
            invocationKey: 'call:first',
            kind: SpaceCapabilityPublicationInput::KIND_SKILL,
            name: 'first-skill',
            description: 'Первый конкурентный навык.',
            instructions: 'Применяй первое правило.',
        );
        $second = self::input(
            snapshot: $snapshot,
            batchId: 'batch_initial',
            terminalScopeId: 'terminal:concurrent-different',
            invocationKey: 'call:second',
            kind: SpaceCapabilityPublicationInput::KIND_SKILL,
            name: 'second-skill',
            description: 'Второй конкурентный навык.',
            instructions: 'Применяй второе правило.',
        );

        $results  = $this->runConcurrent([$first, $second]);
        $statuses = array_column($results, 'status');
        sort($statuses, \SORT_STRING);

        self::assertSame(['error', 'ok'], $statuses);
        self::assertCount(1, array_filter(
            $results,
            static fn (array $result): bool => str_contains((string) ($result['message'] ?? ''), 'stale'),
        ));
        self::assertSame(2, $this->value('SELECT COUNT(*) FROM space_releases'));
        self::assertSame(1, $this->value('SELECT COUNT(*) FROM space_promotion_events'));
        self::assertSame(2, $this->value(
            'SELECT release_generation FROM agent_spaces WHERE id = ?',
            [self::SPACE_ID],
        ));
    }

    public function testFailureRecordingTheTerminalEventRollsBackReleaseSkillsAndCas(): void
    {
        $snapshot = $this->seedSpace();
        $real     = $this->database;
        $faulting = $this->createStub(DatabaseInterface::class);
        $faulting->method('query')->willReturnCallback(
            static fn (string $sql, array $parameters = []) => $real->query($sql, $parameters),
        );
        $faulting->method('execute')->willReturnCallback(
            static function (string $sql, array $parameters = []) use ($real): int {
                if (str_contains($sql, 'INSERT INTO space_promotion_events')) {
                    throw new RuntimeException('injected publication event failure');
                }

                return $real->execute($sql, $parameters);
            },
        );
        $faulting->method('transaction')->willReturnCallback(
            static fn (callable $callback, ?string $isolationLevel = null): mixed => $real->transaction(
                static fn (): mixed => $callback($faulting),
                $isolationLevel,
            ),
        );

        try {
            (new SpaceCapabilityPublisher($faulting))->publish(self::input(
                snapshot: $snapshot,
                batchId: 'batch_initial',
                terminalScopeId: 'terminal:failure',
                invocationKey: 'call:failure',
                kind: SpaceCapabilityPublicationInput::KIND_SKILL,
                name: 'must-rollback',
                description: 'Этот навык не должен сохраниться.',
                instructions: 'Проверь полный rollback транзакции.',
            ));
            self::fail('The injected terminal-event failure must abort publication.');
        } catch (Throwable $error) {
            self::assertStringContainsString('injected publication event failure', $error->getMessage());
        }

        self::assertSame(1, $this->value('SELECT COUNT(*) FROM space_releases'));
        self::assertSame(0, $this->value('SELECT COUNT(*) FROM space_skill_versions'));
        self::assertSame(0, $this->value('SELECT COUNT(*) FROM space_promotion_events'));
        self::assertSame(
            [$snapshot->releaseId, 1],
            array_values($this->row(
                'SELECT active_release_id, release_generation FROM agent_spaces WHERE id = ?',
                [self::SPACE_ID],
            )),
        );
        self::assertSame('active', $this->value(
            'SELECT status FROM space_releases WHERE id = ?',
            [$snapshot->releaseId],
        ));
    }

    public function testPromotionAuthorityLedgerRejectsUpdateAndDelete(): void
    {
        $snapshot = $this->seedSpace();
        $input    = self::input(
            snapshot: $snapshot,
            batchId: 'batch_initial',
            terminalScopeId: 'terminal:immutable-event',
            invocationKey: 'call:immutable-event',
            kind: SpaceCapabilityPublicationInput::KIND_SKILL,
            name: 'immutable-ledger',
            description: 'Проверяет неизменяемость журнала публикации.',
            instructions: 'Оставь журнал публикации append-only.',
        );
        (new SpaceCapabilityPublisher($this->database))->publish($input, 20_000);

        foreach ([
            "UPDATE space_promotion_events SET actor = 'forged'",
            'DELETE FROM space_promotion_events',
        ] as $mutation) {
            $pdo = self::pdo();
            $pdo->exec('SET search_path TO "' . $this->schema . '"');

            try {
                $pdo->exec($mutation);
                self::fail('Promotion authority ledger mutation must be rejected.');
            } catch (Throwable $error) {
                self::assertStringContainsString('space promotion events are immutable', $error->getMessage());
            }
        }
        $pdo = self::pdo();
        $pdo->exec('SET search_path TO "' . $this->schema . '"');
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM space_promotion_events')->fetchColumn());
        self::assertSame(
            'telegram_user:42',
            $pdo->query('SELECT actor FROM space_promotion_events')->fetchColumn(),
        );
    }

    private static function connectDatabase(): DatabaseInterface
    {
        $host    = self::environment('SPACE_PUBLICATION_PG_HOST', '');
        $manager = new DatabaseManager(new DatabaseConfig([
            'default'   => 'default',
            'databases' => [
                'default' => ['connection' => 'postgres'],
            ],
            'connections' => [
                'postgres' => new PostgresDriverConfig(
                    connection: new TcpConnectionConfig(
                        database: self::environment('SPACE_PUBLICATION_PG_DATABASE', 'space_test'),
                        host: $host,
                        port: (int) self::environment('SPACE_PUBLICATION_PG_PORT', '5432'),
                        user: self::environment('SPACE_PUBLICATION_PG_USER', 'space_test'),
                        password: self::environment('SPACE_PUBLICATION_PG_PASSWORD', 'space_test'),
                    ),
                    driver: TrueAsyncPostgresDriver::class,
                    reconnect: true,
                    queryCache: false,
                ),
            ],
        ]));

        return $manager->database();
    }

    private static function input(
        SpaceRuntimeSnapshot $snapshot,
        string $batchId,
        string $terminalScopeId,
        string $invocationKey,
        string $kind,
        string $name,
        string $description,
        string $instructions,
    ): SpaceCapabilityPublicationInput {
        return new SpaceCapabilityPublicationInput(
            spaceId: self::SPACE_ID,
            runtimeSnapshotId: $snapshot->snapshotId,
            terminalScopeId: $terminalScopeId,
            invocationKey: $invocationKey,
            kind: $kind,
            name: $name,
            description: $description,
            instructions: $instructions,
            authorizationProvenance: [
                'spaceId'             => self::SPACE_ID,
                'batchId'             => $batchId,
                'authorization'       => 'telegram-admin',
                'actorParticipantKey' => 'telegram_user:42',
                'requestUpdateId'     => 123,
                'requestSha256'       => 'sha256:' . hash('sha256', $terminalScopeId),
                'quoteSha256'         => 'sha256:' . hash('sha256', $invocationKey),
            ],
        );
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        return json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
    }

    private static function json(mixed $value): string
    {
        return json_encode(
            $value,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }

    private static function environment(string $key, string $default): string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function pdo(): PDO
    {
        return new PDO(sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            self::environment('SPACE_PUBLICATION_PG_HOST', '127.0.0.1'),
            self::environment('SPACE_PUBLICATION_PG_PORT', '5432'),
            self::environment('SPACE_PUBLICATION_PG_DATABASE', 'space_test'),
        ), self::environment('SPACE_PUBLICATION_PG_USER', 'space_test'), self::environment(
            'SPACE_PUBLICATION_PG_PASSWORD',
            'space_test',
        ), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @param list<array{name: string, description: string, body: string, enabled: bool}> $skills */
    private function seedSpace(array $skills = []): SpaceRuntimeSnapshot
    {
        usort($skills, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
        $manifest = [
            'capsules'        => [],
            'futureExtension' => ['keep' => true],
        ];
        if ($skills !== []) {
            $manifest['skillsDigest'] = 'sha256:' . hash('sha256', json_encode(
                $skills,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            ));
        }
        $seed = new SpaceReleaseSeed(
            model: 'model-test',
            prompt: 'Отвечай по делу.',
            personalityJson: '{"tone":"dry"}',
            manifestJson: self::json($manifest),
            capabilityPolicyJson: SpaceCapabilityPolicy::JSON,
            createdBy: 'test-seed',
        );
        $releaseId = 'rel_source_' . bin2hex(random_bytes(8));
        $this->database->execute(<<<'SQL'
            INSERT INTO space_releases (
                id, space_id, parent_release_id, source_proposal_id, sequence, status,
                release_digest, model, prompt, personality_json, manifest_json,
                capability_policy_json, artifact_digest, evaluation_digest,
                created_by, created_at, activated_at
            ) VALUES (?, ?, NULL, NULL, 1, 'active', ?, ?, ?, ?, ?, ?, NULL, NULL, ?, 1, 1)
            SQL, [
            $releaseId,
            self::SPACE_ID,
            $seed->digest(),
            $seed->model,
            $seed->prompt,
            $seed->personalityJson,
            $seed->manifestJson,
            $seed->capabilityPolicyJson,
            $seed->createdBy,
        ]);
        $this->database->execute(<<<'SQL'
            INSERT INTO agent_spaces (
                id, status, active_release_id, release_generation, memory_revision, updated_at
            ) VALUES (?, 'active', ?, 1, 0, 1)
            SQL, [self::SPACE_ID, $releaseId]);
        foreach ($skills as $skill) {
            $this->database->execute(<<<'SQL'
                INSERT INTO space_skill_versions (
                    id, space_id, release_id, name, version, description, body,
                    manifest_json, source_digest, enabled, created_at
                ) VALUES (?, ?, ?, ?, 1, ?, ?, '{}', NULL, ?, 1)
                SQL, [
                'skv_' . hash('sha256', $skill['name']),
                self::SPACE_ID,
                $releaseId,
                $skill['name'],
                $skill['description'],
                $skill['body'],
                SqlBoolean::encode($skill['enabled']),
            ]);
        }

        return $this->snapshot('batch_initial');
    }

    private function snapshot(string $batchId): SpaceRuntimeSnapshot
    {
        return (new SpaceRuntimeSnapshotLoaderActivity(
            $this->database,
            SpaceToolCatalog::wireDefinitions(),
        ))->loadSnapshot(new SpaceRuntimeSnapshotRequest(self::SPACE_ID, $batchId));
    }

    private function delayActiveReleaseCas(): void
    {
        $this->database->execute(<<<'SQL'
            CREATE FUNCTION delay_active_release_cas() RETURNS trigger AS $$
            BEGIN
                PERFORM pg_sleep(0.4);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->database->execute(<<<'SQL'
            CREATE TRIGGER delay_active_release_cas
            BEFORE UPDATE OF active_release_id ON agent_spaces
            FOR EACH ROW EXECUTE FUNCTION delay_active_release_cas()
            SQL);
    }

    /**
     * @param list<SpaceCapabilityPublicationInput> $inputs
     *
     * @return list<array<string, mixed>>
     */
    private function runConcurrent(array $inputs): array
    {
        $workers = [];
        foreach ($inputs as $index => $input) {
            $pipes   = [];
            $process = proc_open(
                [
                    PHP_BINARY,
                    __DIR__ . '/fixtures/space_capability_publish_worker.php',
                    $this->schema,
                ],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__, 3),
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start a publication concurrency worker.');
            }
            fwrite($pipes[0], self::json([
                'request'    => $input->payload(),
                'provenance' => $input->authorizationProvenance,
                'now'        => 10_000 + $index,
            ]) . "\n");
            $workers[] = [
                'process' => $process,
                'stdin'   => $pipes[0],
                'stdout'  => $pipes[1],
                'stderr'  => $pipes[2],
            ];
        }

        foreach ($workers as $worker) {
            fwrite($worker['stdin'], 'x');
            fclose($worker['stdin']);
        }

        $results = [];
        foreach ($workers as $worker) {
            $stdout = stream_get_contents($worker['stdout']);
            $stderr = stream_get_contents($worker['stderr']);
            fclose($worker['stdout']);
            fclose($worker['stderr']);
            $exitCode = proc_close($worker['process']);
            self::assertSame(0, $exitCode, $stderr === false ? '' : $stderr);
            self::assertIsString($stdout);
            $results[] = self::decode($stdout);
        }

        return $results;
    }

    /** @return array<string, mixed> */
    private function row(string $sql, array $parameters = []): array
    {
        $row = $this->database->query($sql, $parameters)->fetch();
        self::assertIsArray($row);

        return $row;
    }

    private function value(string $sql, array $parameters = []): mixed
    {
        return $this->database->query($sql, $parameters)->fetchColumn();
    }

    private function createSchema(): void
    {
        $this->database->execute(<<<'SQL'
            CREATE TABLE agent_spaces (
                id text PRIMARY KEY,
                status text NOT NULL,
                active_release_id text NULL,
                release_generation bigint NOT NULL,
                memory_revision bigint NOT NULL,
                updated_at bigint NOT NULL
            )
            SQL);
        $this->database->execute(<<<'SQL'
            CREATE TABLE space_releases (
                id text PRIMARY KEY,
                space_id text NOT NULL,
                parent_release_id text NULL,
                source_proposal_id text NULL,
                sequence bigint NOT NULL,
                status text NOT NULL,
                release_digest text NOT NULL,
                model text NOT NULL,
                prompt text NOT NULL,
                personality_json text NOT NULL,
                manifest_json text NOT NULL,
                capability_policy_json text NOT NULL,
                artifact_digest text NULL,
                evaluation_digest text NULL,
                created_by text NOT NULL,
                created_at bigint NOT NULL,
                activated_at bigint NULL,
                UNIQUE (space_id, sequence)
            )
            SQL);
        $this->database->execute(<<<'SQL'
            CREATE TABLE space_skill_versions (
                id text PRIMARY KEY,
                space_id text NOT NULL,
                release_id text NOT NULL,
                name text NOT NULL,
                version bigint NOT NULL,
                description text NOT NULL,
                body text NOT NULL,
                manifest_json text NOT NULL,
                source_digest text NULL,
                enabled boolean NOT NULL,
                created_at bigint NOT NULL,
                UNIQUE (release_id, name),
                UNIQUE (space_id, name, version)
            )
            SQL);
        $this->database->execute(<<<'SQL'
            CREATE TABLE space_runtime_snapshots (
                id text PRIMARY KEY,
                space_id text NOT NULL,
                batch_id text NOT NULL,
                release_id text NOT NULL,
                release_generation bigint NOT NULL,
                memory_revision bigint NOT NULL,
                payload_json text NOT NULL,
                created_at bigint NOT NULL,
                UNIQUE (space_id, batch_id)
            )
            SQL);
        $this->database->execute(<<<'SQL'
            CREATE TABLE space_promotion_events (
                id text PRIMARY KEY,
                space_id text NOT NULL,
                proposal_id text NULL,
                from_release_id text NULL,
                to_release_id text NOT NULL,
                action text NOT NULL,
                release_generation_before bigint NOT NULL,
                release_generation_after bigint NOT NULL,
                actor text NOT NULL,
                policy_decision_json text NOT NULL,
                created_at bigint NOT NULL,
                UNIQUE (space_id, release_generation_after)
            )
            SQL);
        $this->database->execute(<<<'SQL'
            CREATE FUNCTION reject_space_promotion_event_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'space promotion events are immutable';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->database->execute(<<<'SQL'
            CREATE TRIGGER space_promotion_events_immutable
            BEFORE UPDATE OR DELETE ON space_promotion_events
            FOR EACH ROW EXECUTE FUNCTION reject_space_promotion_event_mutation()
            SQL);
    }
}
