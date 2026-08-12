<?php

declare(strict_types=1);

namespace Bot\Entity\Space;

use Bot\Entity\SpaceSkillVersion;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * @extends Repository<SpaceSkillVersion>
 */
final class SpaceSkillVersionRepository extends Repository
{
    public function __construct(
        Select $select,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($select);
    }

    /**
     * @param string $releaseId
     * @param bool   $enabledOnly
     *
     * @return array<SpaceSkillVersion>
     */
    public function findForRelease(string $releaseId, bool $enabledOnly = true): array
    {
        $select = $this->select()->where('releaseId', $releaseId);

        if ($enabledOnly) {
            $select = $select->where('enabled', true);
        }

        return $select->orderBy('name', 'ASC')->fetchAll();
    }

    public function create(SpaceSkillVersion $skill, bool $run = true): void
    {
        $this->em->persist($skill);

        $run && $this->em->run();
    }
}
