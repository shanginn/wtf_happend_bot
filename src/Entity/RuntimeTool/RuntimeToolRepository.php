<?php

declare(strict_types=1);

namespace Bot\Entity\RuntimeTool;

use Bot\Entity\RuntimeTool;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * @template T of RuntimeTool
 *
 * @extends Repository<T>
 */
final class RuntimeToolRepository extends Repository
{
    public function __construct(
        Select $select,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($select);
    }

    /**
     * @return array<RuntimeTool>
     */
    public function findByChatId(int $chatId, bool $enabledOnly = true): array
    {
        $select = $this->select()->where('chatId', $chatId);

        if ($enabledOnly) {
            $select = $select->where('enabled', true);
        }

        return $select->orderBy('updatedAt', 'DESC')->fetchAll();
    }

    public function findByName(int $chatId, string $name): ?RuntimeTool
    {
        return $this->select()
            ->where('chatId', $chatId)
            ->where('name', $name)
            ->fetchOne();
    }

    public function findEnabledByName(int $chatId, string $name): ?RuntimeTool
    {
        return $this->select()
            ->where('chatId', $chatId)
            ->where('name', $name)
            ->where('enabled', true)
            ->fetchOne();
    }

    public function save(RuntimeTool $tool, bool $run = true): void
    {
        $this->em->persist($tool);

        $run && $this->em->run();
    }
}
