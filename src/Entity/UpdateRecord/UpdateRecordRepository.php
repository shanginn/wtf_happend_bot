<?php

declare(strict_types=1);

namespace Bot\Entity\UpdateRecord;

use Bot\Entity\UpdateRecord;
use Cycle\Database\Injection\Fragment;
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
     * @param int      $chatId
     * @param int|null $limit  Maximum number of updates to retrieve
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
            ->orderBy('createdAt', 'DESC')
            ->orderBy('updateId', 'DESC')
            ->limit($limit)
            ->fetchAll();
    }

    /**
     * Search all persisted Telegram updates in a chat and return only the newest DB-level candidates.
     *
     * @param list<string> $tokens
     * @param int          $chatId
     * @param int          $limit
     *
     * @return array<UpdateRecord>
     */
    public function searchByPayloadText(int $chatId, array $tokens, int $limit): array
    {
        $query = $this->select()
            ->where('chatId', $chatId);

        foreach ($tokens as $token) {
            $query = $query->where(self::directTextPredicate($token));
        }

        return $query
            ->orderBy('createdAt', 'DESC')
            ->orderBy('updateId', 'DESC')
            ->limit($limit)
            ->fetchAll();
    }

    /**
     * Search one trusted chat inside a half-open Telegram timestamp range.
     *
     * @param list<string> $tokens
     * @param int          $chatId
     * @param int          $startInclusive
     * @param int          $endExclusive
     * @param int          $limit
     * @param int          $offset
     *
     * @return array<UpdateRecord>
     */
    public function searchInPeriod(
        int $chatId,
        int $startInclusive,
        int $endExclusive,
        array $tokens,
        int $limit,
        int $offset = 0,
    ): array {
        $query = $this->select()
            ->where('chatId', $chatId)
            ->where('createdAt', '>=', $startInclusive)
            ->where('createdAt', '<', $endExclusive);

        foreach ($tokens as $token) {
            $query = $query->where(self::directTextPredicate($token));
        }

        return $query
            ->orderBy('createdAt', 'ASC')
            ->orderBy('updateId', 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->fetchAll();
    }

    /**
     * Search persisted Telegram updates without crossing a Space's topic boundary.
     * A null topic is the root chat Space and therefore excludes forum topics.
     *
     * @param list<string> $tokens
     * @param int          $chatId
     * @param ?int         $topicId
     * @param int          $limit
     */
    public function searchByPayloadTextInTopic(
        int $chatId,
        ?int $topicId,
        array $tokens,
        int $limit,
    ): array {
        $query = $this->select()->where('chatId', $chatId);
        $query = $topicId === null
            ? $query->where('topicId', null)
            : $query->where('topicId', $topicId);
        foreach ($tokens as $token) {
            $query = $query->where(self::directTextPredicate($token));
        }

        return $query
            ->orderBy('createdAt', 'DESC')
            ->orderBy('updateId', 'DESC')
            ->limit($limit)
            ->fetchAll();
    }

    /**
     * @param int  $chatId
     * @param ?int $topicId
     * @param int  $limit
     *
     * @return array<UpdateRecord>
     */
    public function findLastNInTopic(int $chatId, ?int $topicId, int $limit): array
    {
        $query = $this->select()
            ->where('chatId', $chatId);

        $query = $topicId === null
            ? $query->where('topicId', null)
            : $query->where('topicId', $topicId);

        return $query
            ->orderBy('createdAt', 'DESC')
            ->orderBy('updateId', 'DESC')
            ->limit($limit)
            ->fetchAll();
    }

    /**
     * Finds updates after a specific update ID for a chat.
     *
     * @param int      $chatId
     * @param int      $afterUpdateId
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

    private static function likePattern(string $token): string
    {
        return '%' . strtr($token, [
            '!' => '!!',
            '%' => '!%',
            '_' => '!_',
        ]) . '%';
    }

    private static function directTextPredicate(string $token): Fragment
    {
        return new Fragment(<<<'SQL'
            lower(concat_ws(' ',
                "update"::jsonb #>> '{effective_message,text}',
                "update"::jsonb #>> '{effective_message,caption}',
                "update"::jsonb #>> '{message,text}',
                "update"::jsonb #>> '{message,caption}',
                "update"::jsonb #>> '{edited_message,text}',
                "update"::jsonb #>> '{edited_message,caption}',
                "update"::jsonb #>> '{channel_post,text}',
                "update"::jsonb #>> '{channel_post,caption}',
                "update"::jsonb #>> '{edited_channel_post,text}',
                "update"::jsonb #>> '{edited_channel_post,caption}',
                "update"::jsonb #>> '{business_message,text}',
                "update"::jsonb #>> '{business_message,caption}',
                "update"::jsonb #>> '{edited_business_message,text}',
                "update"::jsonb #>> '{edited_business_message,caption}',
                "update"::jsonb #>> '{guest_message,text}',
                "update"::jsonb #>> '{guest_message,caption}'
            )) LIKE ? ESCAPE '!'
            SQL, self::likePattern(mb_strtolower($token)));
    }
}
