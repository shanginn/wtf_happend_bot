<?php

declare(strict_types=1);

namespace Bot\Entity\ToolExecutionRecord;

use Bot\Entity\ToolExecutionRecord;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * @template T of ToolExecutionRecord
 *
 * @extends Repository<T>
 */
final class ToolExecutionRecordRepository extends Repository
{
    public function __construct(
        Select $select,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($select);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?ToolExecutionRecord
    {
        return $this->select()
            ->where('idempotencyKey', $idempotencyKey)
            ->fetchOne();
    }

    public function save(ToolExecutionRecord $record, bool $run = true): void
    {
        $this->em->persist($record);

        $run && $this->em->run();
    }
}
