<?php

declare(strict_types=1);

namespace Tests\Infrastructure\CycleORM;

use Bot\Infrastructure\CycleORM\CycleOrmScope;
use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\Schema;
use PHPUnit\Framework\TestCase;

use function Async\await_all;
use function Async\delay;
use function Async\spawn;

final class CycleOrmScopeTest extends TestCase
{
    public function testConcurrentOperationsReceiveIsolatedOrmState(): void
    {
        $scope = new CycleOrmScope(
            new DatabaseManager(new DatabaseConfig()),
            new Schema([]),
        );

        $tasks = [
            spawn(static function () use ($scope): array {
                $first = $scope->current();
                delay(20);
                $second = $scope->current();
                $scope->finalizeCurrent();

                return [$first, $second];
            }),
            spawn(static function () use ($scope): array {
                $first = $scope->current();
                delay(10);
                $second = $scope->current();
                $scope->finalizeCurrent();

                return [$first, $second];
            }),
        ];

        [$results, $errors] = await_all($tasks);

        self::assertSame([], $errors);
        self::assertSame($results[0][0], $results[0][1]);
        self::assertSame($results[1][0], $results[1][1]);
        self::assertNotSame($results[0][0]->orm, $results[1][0]->orm);
        self::assertNotSame($results[0][0]->entityManager, $results[1][0]->entityManager);
    }
}
