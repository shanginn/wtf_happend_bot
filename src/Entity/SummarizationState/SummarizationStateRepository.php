<?php

declare(strict_types=1);

namespace Bot\Entity\SummarizationState;

use Bot\Entity\SummarizationState;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * @template T of SummarizationState
 *
 * @extends Repository<T>
 */
final class SummarizationStateRepository extends Repository
{
    public function __construct(
        Select $select,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($select);
    }

    public function find(string $id): ?SummarizationState
    {
        return $this->findByPK($id);
    }

    public function findByChatAndUserOrNew(int $chatId, int $userId): SummarizationState
    {
        $state = $this->findOne(['chatId' => $chatId, 'userId' => $userId]);

        if ($state === null) {
            // Check if there's a state for this chat (from any user)
            $existingChatState = $this->findOne(['chatId' => $chatId]);
            
            // If there's an existing state for this chat, copy the lastSummarizedMessageId
            // This prevents having to process the entire chat history for new users
            $lastMessageId = $existingChatState?->lastSummarizedMessageId ?? 0;
            
            $state = new SummarizationState($chatId, $userId, $lastMessageId);
            $this->em->persist($state);
        }

        return $state;
    }
    
    /**
     * @deprecated Use findByChatAndUserOrNew() instead
     */
    public function findByChatOrNew(int $chatId): SummarizationState
    {
        $state = $this->findOne(['chatId' => $chatId]);

        if ($state === null) {
            $state = new SummarizationState($chatId, 0, 0);
            $this->em->persist($state);
        }

        return $state;
    }

    public function findBy(array $scope = []): ?SummarizationState
    {
        return parent::findOne($scope);
    }

    public function exists(string $id): bool
    {
        return $this->select()->wherePK($id)->count() > 0;
    }

    public function delete(SummarizationState $message, bool $run = true): void
    {
        $this->em->delete($message);

        $run && $this->em->run();
    }

    public function save(SummarizationState $message, bool $run = true): void
    {
        $this->em->persist($message);

        $run && $this->em->run();
    }
}