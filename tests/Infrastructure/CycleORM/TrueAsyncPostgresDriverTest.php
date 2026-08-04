<?php

declare(strict_types=1);

namespace Tests\Infrastructure\CycleORM;

use Bot\Infrastructure\CycleORM\TrueAsyncPostgresDriver;
use Cycle\Database\Config\Postgres\TcpConnectionConfig;
use Cycle\Database\Config\PostgresDriverConfig;
use Cycle\Database\Driver\Driver;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function Async\await_all;
use function Async\delay;
use function Async\spawn;

final class TrueAsyncPostgresDriverTest extends TestCase
{
    public function testTransactionLevelsAreIsolatedBetweenCoroutines(): void
    {
        $driver = TrueAsyncPostgresDriver::create(
            new PostgresDriverConfig(
                connection: new TcpConnectionConfig(database: 'unused'),
                driver: TrueAsyncPostgresDriver::class,
                queryCache: false,
            ),
        );

        $pdo = new class() extends PDO {
            /** @var array<int, bool> */
            private array $transactions = [];

            public function __construct() {}

            public function beginTransaction(): bool
            {
                $this->transactions[\Async\current_coroutine()->getId()] = true;

                return true;
            }

            public function inTransaction(): bool
            {
                return $this->transactions[\Async\current_coroutine()->getId()] ?? false;
            }

            public function rollBack(): bool
            {
                unset($this->transactions[\Async\current_coroutine()->getId()]);

                return true;
            }
        };

        $pdoProperty = new ReflectionProperty(Driver::class, 'pdo');
        $pdoProperty->setValue($driver, $pdo);

        $tasks = [
            spawn(static function () use ($driver): array {
                $driver->beginTransaction();
                delay(20);
                $beforeRollback = $driver->getTransactionLevel();
                $driver->rollbackTransaction();

                return [$beforeRollback, $driver->getTransactionLevel()];
            }),
            spawn(static function () use ($driver): array {
                $driver->beginTransaction();
                delay(10);
                $beforeRollback = $driver->getTransactionLevel();
                $driver->rollbackTransaction();

                return [$beforeRollback, $driver->getTransactionLevel()];
            }),
        ];

        [$results, $errors] = await_all($tasks);

        self::assertSame([], $errors);
        self::assertSame([[1, 0], [1, 0]], $results);
    }
}
