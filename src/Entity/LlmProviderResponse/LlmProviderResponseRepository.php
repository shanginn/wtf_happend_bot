<?php

declare(strict_types=1);

namespace Bot\Entity\LlmProviderResponse;

use Bot\Entity\LlmProviderResponse;
use Bot\Llm\ProviderHistory\LlmProviderType;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * @template T of LlmProviderResponse
 *
 * @extends Repository<T>
 */
final class LlmProviderResponseRepository extends Repository
{
    public function __construct(
        Select $select,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($select);
    }

    /**
     * @return array<LlmProviderResponse>
     */
    public function findLastNByChat(int $chatId, LlmProviderType $type, int $limit): array
    {
        $records = $this->select()
            ->where('chatId', $chatId)
            ->where('type', $type)
            ->orderBy('createdAt', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->fetchAll();

        return array_reverse($records);
    }

    /**
     * @return array<LlmProviderResponse>
     */
    public function findLastN(int $chatId, ?int $topicId, LlmProviderType $type, int $limit): array
    {
        $query = $this->select()
            ->where('chatId', $chatId)
            ->where('type', $type);

        $query = $topicId === null
            ? $query->where('topicId', null)
            : $query->where('topicId', $topicId);

        $records = $query
            ->orderBy('createdAt', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->fetchAll();

        return array_reverse($records);
    }

    public function save(LlmProviderResponse $record, bool $run = true): void
    {
        $this->em->persist($record);

        $run && $this->em->run();
    }
}
