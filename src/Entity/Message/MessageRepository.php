<?php

declare(strict_types=1);

namespace Bot\Entity\Message;

use Bot\Entity\Message;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\Select;
use Cycle\ORM\Select\Repository;

/**
 * @template T of Message
 *
 * @extends Repository<T>
 */
final class MessageRepository extends Repository
{
    public function __construct(
        Select $select,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($select);
    }

    public function find(string $id): ?Message
    {
        return $this->findByPK($id);
    }

    /**
     * Finds all messages in a chat after a specific message ID.
     *
     * @param int $chatId
     * @param int $messageId the ID of the message *after* which to start fetching
     *
     * @return array<Message>
     */
    public function findAllAfter(int $chatId, int $messageId): array
    {
        return $this->select()
            ->where('chatId', $chatId)
            ->where('messageId', '>', $messageId)
            ->orderBy('messageId', 'ASC')
            ->fetchAll();
    }

    /**
     * Finds messages in a chat starting from a specific message ID, up to a limit.
     *
     * @param int $chatId
     * @param int $startMessageId the ID of the message to start *from* (inclusive)
     * @param int $limit          maximum number of messages to fetch
     *
     * @return array<Message>
     */
    public function findFrom(int $chatId, int $startMessageId, int $limit): array
    {
        return $this->select()
            ->where('chatId', $chatId)
            ->where('messageId', '>=', $startMessageId)
            ->orderBy('messageId', 'ASC')
            ->limit($limit)
            ->fetchAll();
    }

    public function findBy(array $scope = []): ?Message
    {
        return parent::findOne($scope);
    }

    public function exists(string $id): bool
    {
        return $this->select()->wherePK($id)->count() > 0;
    }

    public function delete(Message $message, bool $run = true): void
    {
        $this->em->delete($message);

        $run && $this->em->run();
    }

    public function save(Message $message, bool $run = true): void
    {
        $this->em->persist($message);

        $run && $this->em->run();
    }

    /**
     * Finds the ID of the last message in a specific chat,
     * optionally filtering by user ID.
     *
     * @param int      $chatId The ID of the chat
     * @param int|null $userId Optional user ID to filter by
     *
     * @return int|null The ID of the last message, or null if there are no messages
     */
    public function findLastMessageId(int $chatId, ?int $userId = null): ?int
    {
        $query = $this->select()
            ->where('chatId', $chatId);

        if ($userId !== null) {
            $query = $query->where('fromUserId', $userId);
        }

        $message = $query
            ->orderBy('messageId', 'DESC')
            ->limit(1)
            ->fetchOne();

        return $message?->messageId;
    }

    public function findLastN(int $chatId, int $int): array
    {
        return $this->select()
            ->where('chatId', $chatId)
            ->orderBy('messageId', 'DESC')
            ->limit($int)
            ->fetchAll();
    }
}