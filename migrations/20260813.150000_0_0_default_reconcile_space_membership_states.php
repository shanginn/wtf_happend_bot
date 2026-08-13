<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

/**
 * Seed membership state from historical updates without overwriting a newer
 * transition already accepted by the live B1 membership handler.
 */
final class OrmDefaultReconcileSpaceMembershipStates20260813150000 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->database()->execute(<<<'SQL'
            INSERT INTO space_membership_states (
                bot_instance_id, platform, external_conversation_id,
                last_update_id, membership_status, active, event_at, updated_at
            )
            SELECT
                'default',
                'telegram',
                latest.chat_id::text,
                latest.update_id,
                latest.membership_status,
                latest.membership_status IN ('creator', 'administrator', 'member'),
                latest.event_at,
                latest.event_at
            FROM (
                SELECT DISTINCT ON (record.chat_id)
                    record.chat_id,
                    record.update_id,
                    record.update::jsonb #>> '{my_chat_member,new_chat_member,status}'
                        AS membership_status,
                    COALESCE(
                        (record.update::jsonb #>> '{my_chat_member,date}')::bigint,
                        record.created_at
                    ) AS event_at
                FROM update_records AS record
                WHERE record.update::jsonb #>> '{my_chat_member,new_chat_member,status}'
                    IN ('creator', 'administrator', 'member', 'left', 'kicked')
                ORDER BY record.chat_id, event_at DESC, record.update_id DESC
            ) AS latest
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
            SQL);

        $this->database()->execute(<<<'SQL'
            UPDATE agent_spaces AS space
            SET
                status = CASE WHEN membership.active THEN 'active' ELSE 'retired' END,
                dream_enabled = membership.active,
                updated_at = GREATEST(space.updated_at, membership.event_at)
            FROM space_bindings AS binding
            JOIN space_membership_states AS membership
                ON membership.bot_instance_id = binding.bot_instance_id
                AND membership.platform = binding.platform
                AND membership.external_conversation_id = binding.external_conversation_id
            WHERE binding.space_id = space.id
                AND binding.external_thread_id = ''
            SQL);
    }

    public function down(): void
    {
        // Historical reconciliation is intentionally irreversible. Dropping
        // or rewriting membership rows could erase newer live transitions.
    }
}
