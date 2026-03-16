<?php

declare(strict_types=1);

namespace Bot\Entity\UserMemory;

use Bot\Entity\UserMemory;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * @template T of UserMemory
 *
 * @extends Repository<T>
 */
final class UserMemoryRepository extends Repository
{
    public function __construct(
        Select $select,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($select);
    }

    /**
     * @return array<UserMemory>
     */
    public function findByUser(int $chatId, string $userIdentifier): array
    {
        return $this->select()
            ->where('chatId', $chatId)
            ->where('userIdentifier', $userIdentifier)
            ->orderBy('updatedAt', 'DESC')
            ->fetchAll();
    }

    /**
     * @return array<UserMemory>
     */
    public function findByChat(int $chatId): array
    {
        return $this->select()
            ->where('chatId', $chatId)
            ->orderBy('updatedAt', 'DESC')
            ->fetchAll();
    }

    /**
     * Search memories by content keyword.
     *
     * @return array<UserMemory>
     */
    public function search(int $chatId, string $query, ?string $userIdentifier = null): array
    {
        $select = $this->select()
            ->where('chatId', $chatId);

        if ($userIdentifier !== null) {
            $select = $select->where('userIdentifier', $userIdentifier);
        }

        // Use ILIKE for case-insensitive search
        $select = $select->where('content', 'ILIKE', '%' . $query . '%');

        return $select->orderBy('updatedAt', 'DESC')->fetchAll();
    }

    public function save(UserMemory $memory, bool $run = true): void
    {
        $this->em->persist($memory);

        $run && $this->em->run();
    }

    public function delete(UserMemory $memory, bool $run = true): void
    {
        $this->em->delete($memory);

        $run && $this->em->run();
    }
}
