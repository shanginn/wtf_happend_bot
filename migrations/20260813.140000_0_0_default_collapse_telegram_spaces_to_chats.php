<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;
use RuntimeException;

/**
 * Space identity is one Telegram chat, never a reply thread or forum topic.
 *
 * The first Space import trusted historical update_records.topic_id values.
 * Before TelegramTopicRouting existed, ordinary reply message IDs were stored
 * in that column and produced thousands of empty Space artifacts. This repair
 * is deliberately fail-closed: it only removes untouched legacy seed Spaces
 * with no real topic evidence and no durable child state.
 */
final class OrmDefaultCollapseTelegramSpacesToChats20260813140000 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->database()->execute(<<<'SQL'
            CREATE TEMPORARY TABLE legacy_thread_space_cleanup (
                space_id text PRIMARY KEY,
                release_id text NOT NULL UNIQUE,
                chat_id bigint NOT NULL,
                thread_id bigint NOT NULL
            )
            SQL);

        $this->database()->execute(<<<'SQL'
            INSERT INTO legacy_thread_space_cleanup (space_id, release_id, chat_id, thread_id)
            SELECT space.id, release.id,
                binding.external_conversation_id::bigint,
                binding.external_thread_id::bigint
            FROM agent_spaces AS space
            JOIN space_bindings AS binding ON binding.space_id = space.id
            JOIN space_releases AS release
                ON release.id = space.active_release_id
                AND release.space_id = space.id
            WHERE binding.platform = 'telegram'
                AND binding.external_thread_id <> ''
                AND binding.external_conversation_id ~ '^-?[1-9][0-9]*$'
                AND binding.external_thread_id ~ '^[1-9][0-9]*$'
                AND release.created_by = 'legacy-import'
                AND release.sequence = 1
                AND release.parent_release_id IS NULL
                AND release.source_proposal_id IS NULL
                AND NOT EXISTS (
                    SELECT 1
                    FROM update_records AS record
                    WHERE record.chat_id = binding.external_conversation_id::bigint
                        AND record.topic_id = binding.external_thread_id::bigint
                        AND (
                            record.update::jsonb #>> '{effective_message,is_topic_message}' = 'true'
                            OR record.update::jsonb #>> '{message,is_topic_message}' = 'true'
                            OR record.update::jsonb #>> '{edited_message,is_topic_message}' = 'true'
                            OR record.update::jsonb #>> '{channel_post,is_topic_message}' = 'true'
                            OR record.update::jsonb #>> '{edited_channel_post,is_topic_message}' = 'true'
                        )
                )
            SQL);

        $guard = $this->database()->query(<<<'SQL'
            SELECT
                (SELECT COUNT(*)
                    FROM space_bindings
                    WHERE platform = 'telegram' AND external_thread_id <> '')
                    AS non_root_count,
                (SELECT COUNT(*) FROM legacy_thread_space_cleanup) AS cleanup_count,
                (SELECT COUNT(*) FROM space_skill_versions row
                    JOIN legacy_thread_space_cleanup cleanup ON cleanup.space_id = row.space_id)
                + (SELECT COUNT(*) FROM space_memory_versions row
                    JOIN legacy_thread_space_cleanup cleanup ON cleanup.space_id = row.space_id)
                + (SELECT COUNT(*) FROM space_dream_runs row
                    JOIN legacy_thread_space_cleanup cleanup ON cleanup.space_id = row.space_id)
                + (SELECT COUNT(*) FROM space_upgrade_proposals row
                    JOIN legacy_thread_space_cleanup cleanup ON cleanup.space_id = row.space_id)
                + (SELECT COUNT(*) FROM space_promotion_events row
                    JOIN legacy_thread_space_cleanup cleanup ON cleanup.space_id = row.space_id)
                + (SELECT COUNT(*) FROM space_sandbox_jobs row
                    JOIN legacy_thread_space_cleanup cleanup ON cleanup.space_id = row.space_id)
                + (SELECT COUNT(*) FROM space_runtime_snapshots row
                    JOIN legacy_thread_space_cleanup cleanup ON cleanup.space_id = row.space_id)
                    AS dependent_count
            SQL)->fetch();

        $nonRootCount   = (int) ($guard['non_root_count'] ?? -1);
        $cleanupCount   = (int) ($guard['cleanup_count'] ?? -1);
        $dependentCount = (int) ($guard['dependent_count'] ?? -1);

        if ($nonRootCount !== $cleanupCount) {
            throw new RuntimeException(sprintf(
                'cannot collapse Telegram Spaces: %d non-root bindings, %d verified legacy artifacts',
                $nonRootCount,
                $cleanupCount,
            ));
        }

        if ($dependentCount !== 0) {
            throw new RuntimeException(sprintf(
                'cannot collapse Telegram Spaces: verified legacy artifacts have %d durable dependents',
                $dependentCount,
            ));
        }

        // Preserve actual forum-topic metadata, but repair ordinary reply IDs
        // that the pre-routing ingestion code stored as topic_id.
        $this->database()->execute(<<<'SQL'
            UPDATE update_records
            SET topic_id = NULL
            WHERE topic_id IS NOT NULL
                AND COALESCE(update::jsonb #>> '{effective_message,is_topic_message}', 'false') <> 'true'
                AND COALESCE(update::jsonb #>> '{message,is_topic_message}', 'false') <> 'true'
                AND COALESCE(update::jsonb #>> '{edited_message,is_topic_message}', 'false') <> 'true'
                AND COALESCE(update::jsonb #>> '{channel_post,is_topic_message}', 'false') <> 'true'
                AND COALESCE(update::jsonb #>> '{edited_channel_post,is_topic_message}', 'false') <> 'true'
            SQL);

        $this->database()->execute(<<<'SQL'
            UPDATE agent_spaces AS space
            SET active_release_id = NULL,
                status = 'retired',
                dream_enabled = false,
                updated_at = EXTRACT(EPOCH FROM clock_timestamp())::bigint
            FROM legacy_thread_space_cleanup AS cleanup
            WHERE cleanup.space_id = space.id
            SQL);
        $this->database()->execute(<<<'SQL'
            DELETE FROM space_bindings AS binding
            USING legacy_thread_space_cleanup AS cleanup
            WHERE binding.space_id = cleanup.space_id
            SQL);
        $this->database()->execute(<<<'SQL'
            DELETE FROM space_releases AS release
            USING legacy_thread_space_cleanup AS cleanup
            WHERE release.id = cleanup.release_id
                AND release.space_id = cleanup.space_id
            SQL);
        $this->database()->execute(<<<'SQL'
            DELETE FROM agent_spaces AS space
            USING legacy_thread_space_cleanup AS cleanup
            WHERE space.id = cleanup.space_id
            SQL);

        $remainingNonRootCount = (int) $this->database()->query(<<<'SQL'
            SELECT COUNT(*)
            FROM space_bindings
            WHERE platform = 'telegram' AND external_thread_id <> ''
            SQL)->fetchColumn();
        if ($remainingNonRootCount !== 0) {
            throw new RuntimeException('Telegram Space collapse left non-root bindings behind');
        }
        $this->database()->execute(<<<'SQL'
            ALTER TABLE space_bindings
            ADD CONSTRAINT space_bindings_telegram_chat_scoped
            CHECK (platform <> 'telegram' OR external_thread_id = '')
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE INDEX update_records_index_chat_id_created_at
            ON update_records (chat_id, created_at)
            SQL);
        $this->database()->execute('DROP TABLE legacy_thread_space_cleanup');
    }

    public function down(): void
    {
        $this->database()->execute(
            'ALTER TABLE space_bindings DROP CONSTRAINT space_bindings_telegram_chat_scoped',
        );
        $this->database()->execute('DROP INDEX update_records_index_chat_id_created_at');

        // Historical fake reply-thread Spaces have no meaningful state and
        // cannot be reconstructed. The authoritative root Spaces remain.
    }
}
