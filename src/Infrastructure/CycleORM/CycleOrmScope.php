<?php

declare(strict_types=1);

namespace Bot\Infrastructure\CycleORM;

use Cycle\Database\DatabaseManager;
use Cycle\ORM\EntityManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\SchemaInterface;
use Spiral\Core\Container;
use Throwable;

/**
 * Gives every concurrent Temporal activity isolated ORM, repository, heap and
 * unit-of-work state while retaining one process-wide native PDO pool.
 */
final class CycleOrmScope
{
    /** @var array<int, CycleOrmContext> */
    private array $contexts = [];

    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly SchemaInterface $schema,
    ) {}

    public function current(): CycleOrmContext
    {
        $id = $this->coroutineId();

        return $this->contexts[$id] ??= $this->createContext();
    }

    public function finalizeCurrent(): void
    {
        $id      = $this->coroutineId();
        $context = $this->contexts[$id] ?? null;

        try {
            $context?->clean();

            foreach ($this->databaseManager->getDrivers() as $driver) {
                if ($driver instanceof TrueAsyncPostgresDriver) {
                    $driver->finalizeCurrentCoroutine();
                }
            }
        } finally {
            unset($this->contexts[$id]);
        }
    }

    private function createContext(): CycleOrmContext
    {
        $container = new Container();
        $orm       = new ORM(
            new Factory($this->databaseManager, factory: $container),
            $this->schema,
        );
        $entityManager = new EntityManager($orm);

        $container->bind(ORMInterface::class, $orm);
        $container->bind(EntityManagerInterface::class, $entityManager);

        return new CycleOrmContext($container, $orm, $entityManager);
    }

    private function coroutineId(): int
    {
        if (!function_exists('\Async\current_coroutine')) {
            return 0;
        }

        try {
            return \Async\current_coroutine()->getId();
        } catch (Throwable) {
            return 0;
        }
    }
}
