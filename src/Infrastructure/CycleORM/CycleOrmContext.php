<?php

declare(strict_types=1);

namespace Bot\Infrastructure\CycleORM;

use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Spiral\Core\Container;

final readonly class CycleOrmContext
{
    public function __construct(
        public Container $container,
        public ORMInterface $orm,
        public EntityManagerInterface $entityManager,
    ) {}

    public function clean(): void
    {
        $this->entityManager->clean(cleanHeap: true);
        $this->orm->getHeap()->clean();
    }
}
