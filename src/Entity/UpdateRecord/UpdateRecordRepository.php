<?php

declare(strict_types=1);

namespace Bot\Entity\UpdateRecord;

use Bot\Entity\UpdateRecord;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * @template T of UpdateRecord
 *
 * @extends Repository<T>
 */
final class UpdateRecordRepository extends Repository
{
    public function __construct(
        Select $select,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($select);
    }

    public function find(int $updateId): ?UpdateRecord
    {
        return $this->findByPK($updateId);
    }

    /**
     * Finds all updates for a specific chat, ordered by update ID ascending.
     *
     * @param int $chatId
     * @param int|null $limit Maximum number of updates to retrieve
     *
     * @return array<UpdateRecord>
     */
    public function findByChatId(int $chatId, ?int $limit = null): array
    {
        $query = $this->select()
            ->where('chatId', $chatId)
            ->orderBy('updateId', 'ASC');

        if ($limit !== null) {
            $query = $query->limit($limit);
        }

        return $query->fetchAll();
    }

    /**
     * Finds the last N updates for a specific chat.
     *
     * @param int $chatId
     * @param int $limit
     *
     * @return array<UpdateRecord>
     */
    public function findLastN(int $chatId, int $limit): array
    {
        return $this->select()
            ->where('chatId', $chatId)
            ->orderBy('updateId', 'DESC')
            ->limit($limit)
            ->fetchAll();
    }

    /**
     * Finds updates after a specific update ID for a chat.
     *
     * @param int $chatId
     * @param int $afterUpdateId
     * @param int|null $limit
     *
     * @return array<UpdateRecord>
     */
    public function findAfter(int $chatId, int $afterUpdateId, ?int $limit = null): array
    {
        $query = $this->select()
            ->where('chatId', $chatId)
            ->where('updateId', '>', $afterUpdateId)
            ->orderBy('updateId', 'ASC');

        if ($limit !== null) {
            $query = $query->limit($limit);
        }

        return $query->fetchAll();
    }

    public function save(UpdateRecord $record, bool $run = true): void
    {
        $this->em->persist($record);

        $run && $this->em->run();
    }

    public function delete(UpdateRecord $record, bool $run = true): void
    {
        $this->em->delete($record);

        $run && $this->em->run();
    }

    public function exists(int $updateId): bool
    {
        return $this->select()->wherePK($updateId)->count() > 0;
    }
}
