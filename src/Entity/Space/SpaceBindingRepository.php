<?php

declare(strict_types=1);

namespace Bot\Entity\Space;

use Bot\Entity\SpaceBinding;
use Bot\Space\Persistence\SpaceBindingKey;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * @extends Repository<SpaceBinding>
 */
final class SpaceBindingRepository extends Repository
{
    public function __construct(
        Select $select,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($select);
    }

    public function findByKey(SpaceBindingKey $key): ?SpaceBinding
    {
        return $this->select()
            ->where('botInstanceId', $key->botInstanceId)
            ->where('platform', $key->platform)
            ->where('externalConversationId', $key->externalConversationId)
            ->where('externalThreadId', $key->externalThreadId)
            ->fetchOne();
    }

    public function save(SpaceBinding $binding, bool $run = true): void
    {
        $this->em->persist($binding);

        $run && $this->em->run();
    }
}
