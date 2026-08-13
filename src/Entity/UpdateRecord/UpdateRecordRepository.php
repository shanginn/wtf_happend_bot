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
     * Search one trusted Space topic inside a half-open Telegram timestamp range.
     * A null topic is the root chat Space and therefore excludes forum topics.
     *
     * @param list<string> $tokens
     * @param int          $chatId
     * @param ?int         $topicId
     * @param int          $startInclusive
     * @param int          $endExclusive
     * @param int          $limit
     * @param int          $offset
     *
     * @return array<UpdateRecord>
     */
    public function searchInPeriodInTopic(
        int $chatId,
        ?int $topicId,
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
        $query = $topicId === null
            ? $query->where('topicId', null)
            : $query->where('topicId', $topicId);

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
        $pattern = self::likePattern(mb_strtolower($token));

        return new Fragment(<<<'SQL'
            (
                lower(concat_ws(' ',
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
                OR EXISTS (
                    SELECT 1
                    FROM jsonb_array_elements(jsonb_build_array(
                        "update"::jsonb #> '{message}',
                        "update"::jsonb #> '{edited_message}',
                        "update"::jsonb #> '{channel_post}',
                        "update"::jsonb #> '{edited_channel_post}',
                        "update"::jsonb #> '{business_message}',
                        "update"::jsonb #> '{edited_business_message}',
                        "update"::jsonb #> '{guest_message}'
                    )) AS direct_message(payload)
                    WHERE lower(concat_ws(' ',
                        CASE WHEN jsonb_typeof(payload->'photo') = 'array' THEN
                            CASE WHEN jsonb_array_length(payload->'photo') > 0 THEN 'photo' END
                        END,
                        CASE WHEN jsonb_typeof(payload->'document') = 'object' THEN 'document' END,
                        CASE WHEN jsonb_typeof(payload->'animation') = 'object' THEN 'animation' END,
                        CASE WHEN jsonb_typeof(payload->'audio') = 'object' THEN 'audio' END,
                        CASE WHEN jsonb_typeof(payload->'video') = 'object' THEN 'video' END,
                        CASE WHEN jsonb_typeof(payload->'video_note') = 'object' THEN 'video note' END,
                        CASE WHEN jsonb_typeof(payload->'voice') = 'object' THEN 'voice message' END,
                        CASE WHEN jsonb_typeof(payload->'sticker') = 'object' THEN 'sticker' END,
                        CASE WHEN jsonb_typeof(payload->'poll') = 'object' THEN 'poll' END,
                        CASE WHEN jsonb_typeof(payload->'dice') = 'object' THEN 'dice roll' END,
                        CASE WHEN jsonb_typeof(payload->'contact') = 'object' THEN 'shared a contact' END,
                        CASE WHEN jsonb_typeof(payload->'venue') = 'object' THEN 'shared a venue' END,
                        CASE WHEN jsonb_typeof(payload->'venue') IS DISTINCT FROM 'object'
                            AND jsonb_typeof(payload->'location') = 'object' THEN 'shared a location' END,
                        CASE WHEN jsonb_typeof(payload->'new_chat_members') = 'array' THEN
                            CASE WHEN jsonb_array_length(payload->'new_chat_members') > 0
                                THEN 'added new members' END
                        END,
                        CASE WHEN jsonb_typeof(payload->'left_chat_member') = 'object'
                            THEN 'removed a member' END,
                        CASE WHEN jsonb_typeof(payload->'new_chat_title') = 'string'
                            THEN 'changed the chat title' END,
                        CASE WHEN jsonb_typeof(payload->'new_chat_photo') = 'array' THEN
                            CASE WHEN jsonb_array_length(payload->'new_chat_photo') > 0
                                THEN 'updated the chat photo' END
                        END,
                        CASE WHEN payload->'delete_chat_photo' = 'true'::jsonb
                            THEN 'deleted the chat photo' END,
                        CASE WHEN payload->'group_chat_created' = 'true'::jsonb
                            THEN 'created the group chat' END,
                        CASE WHEN payload->'supergroup_chat_created' = 'true'::jsonb
                            THEN 'created the supergroup' END,
                        CASE WHEN payload->'channel_chat_created' = 'true'::jsonb
                            THEN 'created the channel' END,
                        CASE WHEN jsonb_typeof(payload->'pinned_message') = 'object'
                            THEN 'pinned a message' END,
                        CASE WHEN jsonb_typeof(payload->'invoice') = 'object' THEN 'invoice' END,
                        CASE WHEN jsonb_typeof(payload->'successful_payment') = 'object'
                            THEN 'completed a successful payment' END,
                        CASE WHEN jsonb_typeof(payload->'refunded_payment') = 'object'
                            THEN 'recorded a refunded payment' END,
                        CASE WHEN jsonb_typeof(payload->'story') = 'object' THEN 'forwarded story' END,
                        CASE WHEN jsonb_typeof(payload->'checklist') = 'object' THEN 'checklist' END,
                        CASE WHEN jsonb_typeof(payload->'write_access_allowed') = 'object'
                            THEN 'allowed the bot to write messages' END,
                        CASE WHEN jsonb_typeof(payload->'connected_website') = 'string'
                            THEN 'logged in via a connected website' END,
                        CASE WHEN jsonb_typeof(payload->'web_app_data') = 'object'
                            THEN 'submitted data from a web app' END,
                        CASE WHEN payload->'show_caption_above_media' = 'true'::jsonb
                            THEN 'placed the caption above the media' END,
                        CASE WHEN payload->'has_media_spoiler' = 'true'::jsonb
                            THEN 'marked the media as spoiler' END
                    )) LIKE ? ESCAPE '!'
                )
            )
            SQL, $pattern, $pattern);
    }
}
