<?php

declare(strict_types=1);

namespace Bot\Entity\RuntimeSkill;

use Bot\Entity\RuntimeSkill;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * @template T of RuntimeSkill
 *
 * @extends Repository<T>
 */
final class RuntimeSkillRepository extends Repository
{
    public function __construct(
        Select $select,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($select);
    }

    /**
     * @return array<RuntimeSkill>
     */
    public function findByChatId(int $chatId, bool $enabledOnly = true): array
    {
        $select = $this->select()->where('chatId', $chatId);

        if ($enabledOnly) {
            $select = $select->where('enabled', true);
        }

        return $select->orderBy('updatedAt', 'DESC')->fetchAll();
    }

    public function findByName(int $chatId, string $name): ?RuntimeSkill
    {
        return $this->select()
            ->where('chatId', $chatId)
            ->where('name', $name)
            ->fetchOne();
    }

    public function save(RuntimeSkill $skill, bool $run = true): void
    {
        $this->em->persist($skill);

        $run && $this->em->run();
    }
}
