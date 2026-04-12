<?php

declare(strict_types=1);

namespace Bot\Entity\ParticipantMemory;

use Bot\Entity\ParticipantMemory;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * @template T of ParticipantMemory
 *
 * @extends Repository<T>
 */
final class ParticipantMemoryRepository extends Repository
{
    public function __construct(
        Select $select,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($select);
    }

    public function findExact(int $chatId, string $participantKey, string $memory): ?ParticipantMemory
    {
        return $this->select()
            ->where('chatId', $chatId)
            ->where('participantKey', $participantKey)
            ->where('memory', $memory)
            ->limit(1)
            ->fetchOne();
    }

    /**
     * @return array<ParticipantMemory>
     */
    public function findByParticipantKey(int $chatId, string $participantKey): array
    {
        return $this->select()
            ->where('chatId', $chatId)
            ->where('participantKey', $participantKey)
            ->orderBy('updatedAt', 'DESC')
            ->orderBy('id', 'DESC')
            ->fetchAll();
    }

    /**
     * @return array<ParticipantMemory>
     */
    public function findByChatId(int $chatId): array
    {
        return $this->select()
            ->where('chatId', $chatId)
            ->orderBy('updatedAt', 'DESC')
            ->orderBy('id', 'DESC')
            ->fetchAll();
    }

    public function save(ParticipantMemory $memory, bool $run = true): void
    {
        $this->em->persist($memory);

        $run && $this->em->run();
    }
}
