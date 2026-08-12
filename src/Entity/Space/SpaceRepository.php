<?php

declare(strict_types=1);

namespace Bot\Entity\Space;

use Bot\Entity\Space;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * @extends Repository<Space>
 */
final class SpaceRepository extends Repository
{
    public function __construct(
        Select $select,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($select);
    }

    public function findActive(string $spaceId): ?Space
    {
        return $this->select()
            ->where('id', $spaceId)
            ->where('status', Space::STATUS_ACTIVE)
            ->fetchOne();
    }

    /**
     * @return array<Space>
     */
    public function findDreamEligible(): array
    {
        return $this->select()
            ->where('status', Space::STATUS_ACTIVE)
            ->where('dreamEnabled', true)
            ->orderBy('lastDreamAt', 'ASC')
            ->fetchAll();
    }

    public function save(Space $space, bool $run = true): void
    {
        $this->em->persist($space);

        $run && $this->em->run();
    }
}
