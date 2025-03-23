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

    public function findByChatOrNew(int $chatId): SummarizationState
    {
        $state = $this->findOne(['chatId' => $chatId]);

        if ($state === null) {
            $state = new SummarizationState($chatId, 0);
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