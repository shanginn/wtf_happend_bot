<?php

declare(strict_types=1);

namespace Bot\Entity\Space;

use Bot\Entity\SpaceMemoryVersion;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * @extends Repository<SpaceMemoryVersion>
 */
final class SpaceMemoryVersionRepository extends Repository
{
    public function __construct(
        Select $select,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($select);
    }

    /**
     * @param string  $spaceId
     * @param ?string $participantKey
     *
     * @return array<SpaceMemoryVersion>
     */
    public function findActive(string $spaceId, ?string $participantKey = null): array
    {
        $select = $this->select()
            ->where('spaceId', $spaceId)
            ->where('status', SpaceMemoryVersion::STATUS_ACTIVE);

        if ($participantKey !== null) {
            $select = $select->where('participantKey', $participantKey);
        }

        return $select->orderBy('revision', 'DESC')->fetchAll();
    }

    public function findForSpace(string $spaceId, string $memoryId): ?SpaceMemoryVersion
    {
        return $this->select()
            ->where('spaceId', $spaceId)
            ->where('id', $memoryId)
            ->fetchOne();
    }

    public function findByIdempotencyKey(string $spaceId, string $idempotencyKey): ?SpaceMemoryVersion
    {
        return $this->select()
            ->where('spaceId', $spaceId)
            ->where('idempotencyKey', $idempotencyKey)
            ->fetchOne();
    }

    public function append(SpaceMemoryVersion $memory, bool $run = true): void
    {
        $this->em->persist($memory);

        $run && $this->em->run();
    }
}
