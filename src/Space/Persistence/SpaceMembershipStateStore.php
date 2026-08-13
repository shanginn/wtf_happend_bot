<?php

declare(strict_types=1);

namespace Bot\Space\Persistence;

use Cycle\Database\DatabaseInterface;
use InvalidArgumentException;

final readonly class SpaceMembershipStateStore
{
    private const array SUPPORTED_STATUSES = [
        'creator',
        'administrator',
        'member',
        'left',
        'kicked',
    ];

    public function __construct(private DatabaseInterface $database) {}

    /**
     * @param SpaceBindingKey $conversation
     * @param int             $updateId
     * @param string          $membershipStatus
     * @param bool            $active
     * @param int             $eventAt
     *
     * @return list<string>|null all bound Space IDs, or null when the event is stale or inconsistent
     */
    public function apply(
        SpaceBindingKey $conversation,
        int $updateId,
        string $membershipStatus,
        bool $active,
        int $eventAt,
    ): ?array {
        if (!$conversation->isRoot()) {
            throw new InvalidArgumentException('Membership lifecycle requires a root conversation binding.');
        }
        if ($updateId < 0 || $eventAt < 0) {
            throw new InvalidArgumentException('Membership update ID and event time cannot be negative.');
        }
        if (!in_array($membershipStatus, self::SUPPORTED_STATUSES, true)) {
            throw new InvalidArgumentException('Telegram membership status is unsupported.');
        }

        return $this->database->transaction(function (DatabaseInterface $database) use (
            $conversation,
            $updateId,
            $membershipStatus,
            $active,
            $eventAt,
        ): ?array {
            $accepted = $database->query(<<<'SQL'
                INSERT INTO space_membership_states (
                    bot_instance_id, platform, external_conversation_id,
                    last_update_id, membership_status, active, event_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (bot_instance_id, platform, external_conversation_id)
                DO UPDATE SET
                    last_update_id = EXCLUDED.last_update_id,
                    membership_status = EXCLUDED.membership_status,
                    active = EXCLUDED.active,
                    event_at = EXCLUDED.event_at,
                    updated_at = GREATEST(
                        space_membership_states.updated_at,
                        EXCLUDED.updated_at
                    )
                WHERE
                    space_membership_states.event_at < EXCLUDED.event_at
                    OR (
                        space_membership_states.event_at = EXCLUDED.event_at
                        AND space_membership_states.last_update_id < EXCLUDED.last_update_id
                    )
                    OR (
                        space_membership_states.event_at = EXCLUDED.event_at
                        AND space_membership_states.last_update_id = EXCLUDED.last_update_id
                        AND space_membership_states.membership_status = EXCLUDED.membership_status
                        AND space_membership_states.active = EXCLUDED.active
                    )
                RETURNING last_update_id
                SQL, [
                $conversation->botInstanceId,
                $conversation->platform,
                $conversation->externalConversationId,
                $updateId,
                $membershipStatus,
                $active,
                $eventAt,
                $eventAt,
            ])->fetch();

            if (!is_array($accepted)) {
                return null;
            }

            $rows = $database->query(<<<'SQL'
                UPDATE agent_spaces AS space
                SET
                    status = ?,
                    dream_enabled = ?,
                    updated_at = GREATEST(space.updated_at, ?)
                FROM space_bindings AS binding
                WHERE
                    binding.space_id = space.id
                    AND binding.bot_instance_id = ?
                    AND binding.platform = ?
                    AND binding.external_conversation_id = ?
                    AND binding.external_thread_id = ''
                RETURNING space.id
                SQL, [
                $active ? 'active' : 'retired',
                $active,
                $eventAt,
                $conversation->botInstanceId,
                $conversation->platform,
                $conversation->externalConversationId,
            ])->fetchAll();

            $spaceIds = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $spaceIds[(string) $row['id']] = true;
                }
            }

            return array_keys($spaceIds);
        });
    }
}
