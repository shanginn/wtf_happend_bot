<?php

declare(strict_types=1);

namespace Bot\Entity\ModelCompletionRecord;

use Bot\Entity\ModelCompletionRecord;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * @template T of ModelCompletionRecord
 *
 * @extends Repository<T>
 */
final class ModelCompletionRecordRepository extends Repository
{
    public function __construct(
        Select $select,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($select);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?ModelCompletionRecord
    {
        return $this->select()
            ->where('idempotencyKey', $idempotencyKey)
            ->fetchOne();
    }

    public function save(ModelCompletionRecord $record, bool $run = true): void
    {
        $this->em->persist($record);

        $run && $this->em->run();
    }
}
