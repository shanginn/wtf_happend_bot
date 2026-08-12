<?php

declare(strict_types=1);

namespace Bot\Entity\Space;

use Bot\Entity\SpaceRelease;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * Release content is append-only. Only lifecycle fields are changed after creation.
 *
 * @extends Repository<SpaceRelease>
 */
final class SpaceReleaseRepository extends Repository
{
    public function __construct(
        Select $select,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($select);
    }

    public function findForSpace(string $spaceId, string $releaseId): ?SpaceRelease
    {
        return $this->select()
            ->where('spaceId', $spaceId)
            ->where('id', $releaseId)
            ->fetchOne();
    }

    public function findByProposal(string $spaceId, string $proposalId): ?SpaceRelease
    {
        return $this->select()
            ->where('spaceId', $spaceId)
            ->where('sourceProposalId', $proposalId)
            ->fetchOne();
    }

    /**
     * @param string $spaceId
     *
     * @return array<SpaceRelease>
     */
    public function findHistory(string $spaceId): array
    {
        return $this->select()
            ->where('spaceId', $spaceId)
            ->orderBy('sequence', 'DESC')
            ->fetchAll();
    }

    public function create(SpaceRelease $release, bool $run = true): void
    {
        $this->em->persist($release);

        $run && $this->em->run();
    }

    public function saveLifecycle(SpaceRelease $release, bool $run = true): void
    {
        $this->em->persist($release);

        $run && $this->em->run();
    }
}
