<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

final class OrmDefaultCreateSpaceV220260812150000 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->database()->execute(<<<'SQL'
            CREATE INDEX update_records_index_chat_id_topic_id_created_at
            ON update_records (chat_id, topic_id, created_at)
            SQL);

        $this->database()->execute(<<<'SQL'
            CREATE TABLE agent_spaces (
                id text PRIMARY KEY,
                status text NOT NULL DEFAULT 'active',
                active_release_id text NULL,
                release_generation bigint NOT NULL DEFAULT 0,
                memory_revision bigint NOT NULL DEFAULT 0,
                dream_enabled boolean NOT NULL DEFAULT true,
                dream_time_zone text NOT NULL DEFAULT 'Asia/Yekaterinburg',
                last_dream_at bigint NULL,
                created_at bigint NOT NULL,
                updated_at bigint NOT NULL
            )
            SQL);
        $this->database()->execute(
            'CREATE INDEX agent_spaces_index_status_dream_enabled ON agent_spaces (status, dream_enabled)'
        );
        $this->database()->execute(
            'CREATE INDEX agent_spaces_index_active_release_id ON agent_spaces (active_release_id)'
        );

        $this->database()->execute(<<<'SQL'
            CREATE TABLE space_bindings (
                id text PRIMARY KEY,
                space_id text NOT NULL REFERENCES agent_spaces (id),
                bot_instance_id text NOT NULL,
                platform text NOT NULL,
                external_conversation_id text NOT NULL,
                external_thread_id text NOT NULL DEFAULT '',
                created_at bigint NOT NULL,
                CONSTRAINT space_bindings_unique_external
                    UNIQUE (bot_instance_id, platform, external_conversation_id, external_thread_id)
            )
            SQL);
        $this->database()->execute(
            'CREATE INDEX space_bindings_index_space_id ON space_bindings (space_id)'
        );

        $this->database()->execute(<<<'SQL'
            CREATE TABLE space_releases (
                id text PRIMARY KEY,
                space_id text NOT NULL REFERENCES agent_spaces (id),
                parent_release_id text NULL,
                source_proposal_id text NULL,
                sequence bigint NOT NULL,
                status text NOT NULL,
                release_digest text NOT NULL,
                model text NOT NULL,
                prompt text NOT NULL,
                personality_json text NOT NULL DEFAULT '{}',
                manifest_json text NOT NULL DEFAULT '{}',
                capability_policy_json text NOT NULL
                    DEFAULT '{"version":1,"capsuleNetwork":"deny","crossSpaceReads":false}',
                artifact_digest text NULL,
                evaluation_digest text NULL,
                created_by text NOT NULL,
                created_at bigint NOT NULL,
                activated_at bigint NULL,
                CONSTRAINT space_releases_unique_sequence UNIQUE (space_id, sequence),
                CONSTRAINT space_releases_unique_id_space UNIQUE (id, space_id),
                CONSTRAINT space_releases_foreign_parent_release_id_6a7c8c6fc26c9
                    FOREIGN KEY (parent_release_id) REFERENCES space_releases (id),
                CONSTRAINT space_releases_parent_same_space_fk
                    FOREIGN KEY (parent_release_id, space_id)
                    REFERENCES space_releases (id, space_id)
            )
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE UNIQUE INDEX space_releases_index_source_proposal_id
            ON space_releases (source_proposal_id)
            SQL);
        $this->database()->execute(
            'CREATE INDEX space_releases_index_space_id_status ON space_releases (space_id, status)'
        );
        $this->database()->execute(<<<'SQL'
            ALTER TABLE agent_spaces
            ADD CONSTRAINT agent_spaces_active_release_fk
            FOREIGN KEY (active_release_id, id) REFERENCES space_releases (id, space_id)
            SQL);
        $this->database()->execute(<<<'SQL'
            ALTER TABLE agent_spaces
            ADD CONSTRAINT agent_spaces_foreign_active_release_id_6a7c8c6fc260a
            FOREIGN KEY (active_release_id) REFERENCES space_releases (id)
            SQL);

        $this->database()->execute(<<<'SQL'
            CREATE TABLE space_skill_versions (
                id text PRIMARY KEY,
                space_id text NOT NULL REFERENCES agent_spaces (id),
                release_id text NOT NULL,
                name text NOT NULL,
                version bigint NOT NULL,
                description text NOT NULL,
                body text NOT NULL,
                manifest_json text NOT NULL DEFAULT '{}',
                source_digest text NULL,
                enabled boolean NOT NULL DEFAULT true,
                created_at bigint NOT NULL,
                CONSTRAINT space_skill_versions_unique_release_name UNIQUE (release_id, name),
                CONSTRAINT space_skill_versions_unique_name_version UNIQUE (space_id, name, version),
                CONSTRAINT space_skill_versions_foreign_release_id_6a7c8c6fc2624
                    FOREIGN KEY (release_id) REFERENCES space_releases (id),
                CONSTRAINT space_skill_versions_release_same_space_fk
                    FOREIGN KEY (release_id, space_id)
                    REFERENCES space_releases (id, space_id)
            )
            SQL);
        $this->database()->execute(
            'CREATE INDEX space_skill_versions_index_release_id_enabled ON space_skill_versions (release_id, enabled)'
        );

        $this->database()->execute(<<<'SQL'
            CREATE TABLE space_memory_versions (
                id text PRIMARY KEY,
                space_id text NOT NULL REFERENCES agent_spaces (id),
                revision bigint NOT NULL,
                participant_key text NOT NULL,
                participant_label text NOT NULL,
                memory text NOT NULL,
                quote text NOT NULL,
                context text NOT NULL,
                status text NOT NULL DEFAULT 'active',
                idempotency_key text NULL,
                supersedes_memory_id text NULL,
                provenance_json text NOT NULL DEFAULT '{}',
                confidence_permille integer NULL,
                created_at bigint NOT NULL,
                source_updated_at bigint NOT NULL,
                CONSTRAINT space_memory_versions_unique_revision UNIQUE (space_id, revision),
                CONSTRAINT space_memory_versions_unique_id_space UNIQUE (id, space_id),
                CONSTRAINT b792b8c07cae3be3634e3dfef9355a7d
                    FOREIGN KEY (supersedes_memory_id) REFERENCES space_memory_versions (id),
                CONSTRAINT space_memory_versions_supersedes_same_space_fk
                    FOREIGN KEY (supersedes_memory_id, space_id)
                    REFERENCES space_memory_versions (id, space_id)
            )
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE UNIQUE INDEX space_memory_versions_index_space_id_idempotency_key
            ON space_memory_versions (space_id, idempotency_key)
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE INDEX space_memory_versions_index_space_participant_status
            ON space_memory_versions (space_id, participant_key, status)
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE INDEX space_memory_versions_index_supersedes_memory_id
            ON space_memory_versions (supersedes_memory_id)
            SQL);

        $this->createDreamLifecycleTables();
        $this->importLegacyRootSpaces();
        $this->installImmutabilityGuards();
    }

    public function down(): void
    {
        $this->database()->execute('DROP TRIGGER IF EXISTS space_memory_versions_immutable_content ON space_memory_versions');
        $this->database()->execute('DROP TRIGGER IF EXISTS space_memory_versions_no_delete ON space_memory_versions');
        $this->database()->execute('DROP TRIGGER IF EXISTS space_sandbox_jobs_immutable_request ON space_sandbox_jobs');
        $this->database()->execute('DROP TRIGGER IF EXISTS space_upgrade_proposals_immutable_content ON space_upgrade_proposals');
        $this->database()->execute('DROP TRIGGER IF EXISTS space_skill_versions_insert_requires_mutable_release ON space_skill_versions');
        $this->database()->execute('DROP TRIGGER IF EXISTS space_skill_versions_immutable ON space_skill_versions');
        $this->database()->execute('DROP TRIGGER IF EXISTS space_releases_immutable_content ON space_releases');
        $this->database()->execute('DROP FUNCTION IF EXISTS reject_space_memory_delete()');
        $this->database()->execute('DROP FUNCTION IF EXISTS protect_space_memory_content()');
        $this->database()->execute('DROP FUNCTION IF EXISTS protect_space_sandbox_job_request()');
        $this->database()->execute('DROP FUNCTION IF EXISTS protect_space_upgrade_proposal_content()');
        $this->database()->execute('DROP FUNCTION IF EXISTS require_mutable_space_release_for_skill()');
        $this->database()->execute('DROP FUNCTION IF EXISTS reject_space_skill_mutation()');
        $this->database()->execute('DROP FUNCTION IF EXISTS protect_space_release_content()');

        $this->database()->execute('DROP TABLE space_sandbox_jobs');
        $this->database()->execute('DROP TABLE space_promotion_events');
        $this->database()->execute('DROP TABLE space_evaluation_runs');
        $this->database()->execute('DROP TABLE space_upgrade_proposals');
        $this->database()->execute('DROP TABLE space_dream_runs');
        $this->database()->execute('DROP TABLE space_memory_versions');
        $this->database()->execute('DROP TABLE space_skill_versions');
        $this->database()->execute(<<<'SQL'
            ALTER TABLE agent_spaces
            DROP CONSTRAINT agent_spaces_foreign_active_release_id_6a7c8c6fc260a
            SQL);
        $this->database()->execute('ALTER TABLE agent_spaces DROP CONSTRAINT agent_spaces_active_release_fk');
        $this->database()->execute('DROP TABLE space_releases');
        $this->database()->execute('DROP TABLE space_bindings');
        $this->database()->execute('DROP TABLE agent_spaces');
        $this->database()->execute('DROP INDEX IF EXISTS update_records_index_chat_id_topic_id_created_at');
    }

    private static function deterministicRecordId(string $seed): string
    {
        $hash = md5($seed);

        return sprintf(
            '%s-%s-4%s-a%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            substr($hash, 17, 3),
            substr($hash, 20, 12),
        );
    }

    private function createDreamLifecycleTables(): void
    {
        $this->database()->execute(<<<'SQL'
            CREATE TABLE space_dream_runs (
                id text PRIMARY KEY,
                space_id text NOT NULL REFERENCES agent_spaces (id),
                baseline_release_id text NOT NULL,
                dream_date text NOT NULL,
                execution_token text NOT NULL,
                execution_chain_token text NOT NULL,
                execution_attempt integer NOT NULL CHECK (execution_attempt > 0),
                execution_generation bigint NOT NULL CHECK (execution_generation > 0),
                status text NOT NULL,
                trigger text NOT NULL DEFAULT 'nightly',
                evidence_from bigint NULL,
                evidence_to bigint NULL,
                proposed_release_id text NULL,
                summary_json text NOT NULL DEFAULT '{}',
                created_at bigint NOT NULL,
                started_at bigint NULL,
                heartbeat_at bigint NULL,
                completed_at bigint NULL,
                CONSTRAINT space_dream_runs_unique_date UNIQUE (space_id, dream_date),
                CONSTRAINT space_dream_runs_unique_id_space UNIQUE (id, space_id),
                CONSTRAINT "85a1c0c273b460fb55a73338dfd876ec"
                    FOREIGN KEY (baseline_release_id) REFERENCES space_releases (id),
                CONSTRAINT b52e1d2708919c6687708ab2b3cbbf2d
                    FOREIGN KEY (proposed_release_id) REFERENCES space_releases (id),
                CONSTRAINT space_dream_runs_baseline_same_space_fk
                    FOREIGN KEY (baseline_release_id, space_id)
                    REFERENCES space_releases (id, space_id),
                CONSTRAINT space_dream_runs_proposed_same_space_fk
                    FOREIGN KEY (proposed_release_id, space_id)
                    REFERENCES space_releases (id, space_id)
            )
            SQL);
        $this->database()->execute(
            'CREATE INDEX space_dream_runs_index_status_started_at ON space_dream_runs (status, started_at)'
        );

        $this->database()->execute(<<<'SQL'
            CREATE TABLE space_upgrade_proposals (
                id text PRIMARY KEY,
                space_id text NOT NULL REFERENCES agent_spaces (id),
                dream_run_id text NOT NULL,
                baseline_release_id text NOT NULL,
                candidate_release_id text NOT NULL,
                hypothesis text NOT NULL,
                risk_class text NOT NULL,
                status text NOT NULL,
                proposal_fingerprint text NOT NULL,
                proposal_json text NOT NULL,
                requested_capabilities_json text NOT NULL DEFAULT '[]',
                evidence_json text NOT NULL DEFAULT '{}',
                created_at bigint NOT NULL,
                decided_at bigint NULL,
                CONSTRAINT space_upgrade_proposals_fingerprint_format
                    CHECK (proposal_fingerprint ~ '^sha256:[a-f0-9]{64}$'),
                CONSTRAINT space_upgrade_proposals_unique_candidate UNIQUE (candidate_release_id),
                CONSTRAINT space_upgrade_proposals_unique_id_space UNIQUE (id, space_id),
                CONSTRAINT ae37ece5841a5f75c2f85af16946bf57
                    FOREIGN KEY (dream_run_id) REFERENCES space_dream_runs (id),
                CONSTRAINT "44fe81c4462bbd680d93a7fb5f0effe4"
                    FOREIGN KEY (baseline_release_id) REFERENCES space_releases (id),
                CONSTRAINT "76e9f3a6139bc31d18c41237cb1aa280"
                    FOREIGN KEY (candidate_release_id) REFERENCES space_releases (id),
                CONSTRAINT space_upgrade_proposals_dream_same_space_fk
                    FOREIGN KEY (dream_run_id, space_id)
                    REFERENCES space_dream_runs (id, space_id),
                CONSTRAINT space_upgrade_proposals_baseline_same_space_fk
                    FOREIGN KEY (baseline_release_id, space_id)
                    REFERENCES space_releases (id, space_id),
                CONSTRAINT space_upgrade_proposals_candidate_same_space_fk
                    FOREIGN KEY (candidate_release_id, space_id)
                    REFERENCES space_releases (id, space_id)
            )
            SQL);
        $this->database()->execute(
            'CREATE INDEX space_upgrade_proposals_index_space_id_status ON space_upgrade_proposals (space_id, status)'
        );
        $this->database()->execute(
            'CREATE INDEX space_upgrade_proposals_index_dream_run_id ON space_upgrade_proposals (dream_run_id)'
        );

        $this->database()->execute(<<<'SQL'
            CREATE TABLE space_evaluation_runs (
                id text PRIMARY KEY,
                proposal_id text NOT NULL REFERENCES space_upgrade_proposals (id),
                evaluator_version text NOT NULL,
                suite_digest text NOT NULL,
                status text NOT NULL,
                baseline_score_json text NOT NULL DEFAULT '{}',
                candidate_score_json text NOT NULL DEFAULT '{}',
                metrics_json text NOT NULL DEFAULT '{}',
                artifact_uri text NULL,
                started_at bigint NOT NULL,
                completed_at bigint NULL,
                CONSTRAINT space_evaluation_runs_unique_proposal UNIQUE (proposal_id)
            )
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE INDEX space_evaluation_runs_index_proposal_id_status
            ON space_evaluation_runs (proposal_id, status)
            SQL);
        $this->database()->execute(
            'CREATE INDEX space_evaluation_runs_index_suite_digest ON space_evaluation_runs (suite_digest)'
        );

        $this->database()->execute(<<<'SQL'
            CREATE TABLE space_promotion_events (
                id text PRIMARY KEY,
                space_id text NOT NULL REFERENCES agent_spaces (id),
                proposal_id text NULL,
                from_release_id text NULL,
                to_release_id text NOT NULL,
                action text NOT NULL,
                release_generation_before bigint NOT NULL,
                release_generation_after bigint NOT NULL,
                actor text NOT NULL,
                policy_decision_json text NOT NULL DEFAULT '{}',
                created_at bigint NOT NULL,
                CONSTRAINT space_promotion_events_unique_generation
                    UNIQUE (space_id, release_generation_after),
                CONSTRAINT space_promotion_events_foreign_proposal_id_6a7c8c6fc278c
                    FOREIGN KEY (proposal_id) REFERENCES space_upgrade_proposals (id),
                CONSTRAINT "98677a76ef13cffd4a37e6deb800d63b"
                    FOREIGN KEY (from_release_id) REFERENCES space_releases (id),
                CONSTRAINT "899e930789b463190e74722117b34cb1"
                    FOREIGN KEY (to_release_id) REFERENCES space_releases (id),
                CONSTRAINT space_promotion_events_proposal_same_space_fk
                    FOREIGN KEY (proposal_id, space_id)
                    REFERENCES space_upgrade_proposals (id, space_id),
                CONSTRAINT space_promotion_events_from_release_same_space_fk
                    FOREIGN KEY (from_release_id, space_id)
                    REFERENCES space_releases (id, space_id),
                CONSTRAINT space_promotion_events_to_release_same_space_fk
                    FOREIGN KEY (to_release_id, space_id)
                    REFERENCES space_releases (id, space_id)
            )
            SQL);
        $this->database()->execute(
            'CREATE INDEX space_promotion_events_index_proposal_id ON space_promotion_events (proposal_id)'
        );

        $this->database()->execute(<<<'SQL'
            CREATE TABLE space_sandbox_jobs (
                id text PRIMARY KEY,
                space_id text NOT NULL REFERENCES agent_spaces (id),
                dream_run_id text NULL,
                proposal_id text NULL,
                release_id text NOT NULL,
                job_type text NOT NULL,
                idempotency_key text NOT NULL UNIQUE,
                request_fingerprint text NOT NULL,
                request_json text NOT NULL,
                status text NOT NULL,
                runtime_image_digest text NOT NULL,
                resource_limits_json text NOT NULL DEFAULT '{}',
                capability_policy_json text NOT NULL DEFAULT '{}',
                input_artifact_uri text NULL,
                output_artifact_uri text NULL,
                result_json text NULL,
                error text NULL,
                created_at bigint NOT NULL,
                started_at bigint NULL,
                heartbeat_at bigint NULL,
                completed_at bigint NULL,
                CONSTRAINT space_sandbox_jobs_request_fingerprint_format
                    CHECK (request_fingerprint ~ '^sha256:[a-f0-9]{64}$'),
                CONSTRAINT space_sandbox_jobs_foreign_dream_run_id_6a7c8c6fc2648
                    FOREIGN KEY (dream_run_id) REFERENCES space_dream_runs (id),
                CONSTRAINT space_sandbox_jobs_foreign_proposal_id_6a7c8c6fc269e
                    FOREIGN KEY (proposal_id) REFERENCES space_upgrade_proposals (id),
                CONSTRAINT space_sandbox_jobs_foreign_release_id_6a7c8c6fc26b0
                    FOREIGN KEY (release_id) REFERENCES space_releases (id),
                CONSTRAINT space_sandbox_jobs_dream_same_space_fk
                    FOREIGN KEY (dream_run_id, space_id)
                    REFERENCES space_dream_runs (id, space_id),
                CONSTRAINT space_sandbox_jobs_proposal_same_space_fk
                    FOREIGN KEY (proposal_id, space_id)
                    REFERENCES space_upgrade_proposals (id, space_id),
                CONSTRAINT space_sandbox_jobs_release_same_space_fk
                    FOREIGN KEY (release_id, space_id)
                    REFERENCES space_releases (id, space_id)
            )
            SQL);
        $this->database()->execute(
            'CREATE INDEX space_sandbox_jobs_index_space_id_status ON space_sandbox_jobs (space_id, status)'
        );
        $this->database()->execute(
            'CREATE INDEX space_sandbox_jobs_index_dream_run_id ON space_sandbox_jobs (dream_run_id)'
        );
        $this->database()->execute(
            'CREATE INDEX space_sandbox_jobs_index_proposal_id ON space_sandbox_jobs (proposal_id)'
        );
    }

    private function installImmutabilityGuards(): void
    {
        $this->database()->execute(<<<'SQL'
            CREATE FUNCTION protect_space_release_content() RETURNS trigger AS $$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.space_id IS DISTINCT FROM OLD.space_id
                    OR NEW.parent_release_id IS DISTINCT FROM OLD.parent_release_id
                    OR NEW.source_proposal_id IS DISTINCT FROM OLD.source_proposal_id
                    OR NEW.sequence IS DISTINCT FROM OLD.sequence
                    OR NEW.release_digest IS DISTINCT FROM OLD.release_digest
                    OR NEW.model IS DISTINCT FROM OLD.model
                    OR NEW.prompt IS DISTINCT FROM OLD.prompt
                    OR NEW.personality_json IS DISTINCT FROM OLD.personality_json
                    OR NEW.manifest_json IS DISTINCT FROM OLD.manifest_json
                    OR NEW.capability_policy_json IS DISTINCT FROM OLD.capability_policy_json
                    OR NEW.artifact_digest IS DISTINCT FROM OLD.artifact_digest
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'space release content is immutable';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE TRIGGER space_releases_immutable_content
            BEFORE UPDATE ON space_releases
            FOR EACH ROW EXECUTE FUNCTION protect_space_release_content()
            SQL);

        $this->database()->execute(<<<'SQL'
            CREATE FUNCTION reject_space_skill_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'space skill versions are immutable';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE TRIGGER space_skill_versions_immutable
            BEFORE UPDATE OR DELETE ON space_skill_versions
            FOR EACH ROW EXECUTE FUNCTION reject_space_skill_mutation()
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE FUNCTION require_mutable_space_release_for_skill() RETURNS trigger AS $$
            DECLARE
                release_status text;
            BEGIN
                SELECT status INTO release_status
                FROM space_releases
                WHERE id = NEW.release_id AND space_id = NEW.space_id
                FOR SHARE;

                IF release_status IS NULL THEN
                    RAISE EXCEPTION 'space skill release does not exist in the Space';
                END IF;
                IF release_status NOT IN ('draft', 'building') THEN
                    RAISE EXCEPTION 'skills can only be inserted into a draft or building release';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE TRIGGER space_skill_versions_insert_requires_mutable_release
            BEFORE INSERT ON space_skill_versions
            FOR EACH ROW EXECUTE FUNCTION require_mutable_space_release_for_skill()
            SQL);

        $this->database()->execute(<<<'SQL'
            CREATE FUNCTION protect_space_upgrade_proposal_content() RETURNS trigger AS $$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.space_id IS DISTINCT FROM OLD.space_id
                    OR NEW.dream_run_id IS DISTINCT FROM OLD.dream_run_id
                    OR NEW.baseline_release_id IS DISTINCT FROM OLD.baseline_release_id
                    OR NEW.candidate_release_id IS DISTINCT FROM OLD.candidate_release_id
                    OR NEW.hypothesis IS DISTINCT FROM OLD.hypothesis
                    OR NEW.risk_class IS DISTINCT FROM OLD.risk_class
                    OR NEW.proposal_fingerprint IS DISTINCT FROM OLD.proposal_fingerprint
                    OR NEW.proposal_json IS DISTINCT FROM OLD.proposal_json
                    OR NEW.requested_capabilities_json IS DISTINCT FROM OLD.requested_capabilities_json
                    OR NEW.evidence_json IS DISTINCT FROM OLD.evidence_json
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'space upgrade proposal content is immutable';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE TRIGGER space_upgrade_proposals_immutable_content
            BEFORE UPDATE ON space_upgrade_proposals
            FOR EACH ROW EXECUTE FUNCTION protect_space_upgrade_proposal_content()
            SQL);

        $this->database()->execute(<<<'SQL'
            CREATE FUNCTION protect_space_sandbox_job_request() RETURNS trigger AS $$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.space_id IS DISTINCT FROM OLD.space_id
                    OR NEW.dream_run_id IS DISTINCT FROM OLD.dream_run_id
                    OR NEW.proposal_id IS DISTINCT FROM OLD.proposal_id
                    OR NEW.release_id IS DISTINCT FROM OLD.release_id
                    OR NEW.job_type IS DISTINCT FROM OLD.job_type
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                    OR NEW.request_fingerprint IS DISTINCT FROM OLD.request_fingerprint
                    OR NEW.request_json IS DISTINCT FROM OLD.request_json
                    OR NEW.resource_limits_json IS DISTINCT FROM OLD.resource_limits_json
                    OR NEW.capability_policy_json IS DISTINCT FROM OLD.capability_policy_json
                    OR NEW.input_artifact_uri IS DISTINCT FROM OLD.input_artifact_uri
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'space sandbox job request is immutable';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE TRIGGER space_sandbox_jobs_immutable_request
            BEFORE UPDATE ON space_sandbox_jobs
            FOR EACH ROW EXECUTE FUNCTION protect_space_sandbox_job_request()
            SQL);

        $this->database()->execute(<<<'SQL'
            CREATE FUNCTION protect_space_memory_content() RETURNS trigger AS $$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.space_id IS DISTINCT FROM OLD.space_id
                    OR NEW.revision IS DISTINCT FROM OLD.revision
                    OR NEW.participant_key IS DISTINCT FROM OLD.participant_key
                    OR NEW.participant_label IS DISTINCT FROM OLD.participant_label
                    OR NEW.memory IS DISTINCT FROM OLD.memory
                    OR NEW.quote IS DISTINCT FROM OLD.quote
                    OR NEW.context IS DISTINCT FROM OLD.context
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                    OR NEW.supersedes_memory_id IS DISTINCT FROM OLD.supersedes_memory_id
                    OR NEW.provenance_json IS DISTINCT FROM OLD.provenance_json
                    OR NEW.confidence_permille IS DISTINCT FROM OLD.confidence_permille
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR NEW.source_updated_at IS DISTINCT FROM OLD.source_updated_at
                THEN
                    RAISE EXCEPTION 'space memory content is immutable';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT (OLD.status = 'active' AND NEW.status = 'superseded')
                THEN
                    RAISE EXCEPTION 'space memory status transition is invalid';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE TRIGGER space_memory_versions_immutable_content
            BEFORE UPDATE ON space_memory_versions
            FOR EACH ROW EXECUTE FUNCTION protect_space_memory_content()
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE FUNCTION reject_space_memory_delete() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'space memory versions cannot be deleted';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE TRIGGER space_memory_versions_no_delete
            BEFORE DELETE ON space_memory_versions
            FOR EACH ROW EXECUTE FUNCTION reject_space_memory_delete()
            SQL);
    }

    private function importLegacyRootSpaces(): void
    {
        $now         = time();
        $legacyChats = $this->database()->query(<<<'SQL'
            SELECT chat_id, '' AS thread_id
            FROM update_records
            UNION
            SELECT chat_id, '' AS thread_id FROM runtime_skills
            UNION
            SELECT chat_id, '' AS thread_id FROM participant_memories
            ORDER BY chat_id, thread_id
            SQL)->fetchAll();

        foreach ($legacyChats as $legacyChat) {
            $chatId            = (string) $legacyChat['chat_id'];
            $threadId          = (string) $legacyChat['thread_id'];
            $canonicalIdentity = implode("\0", [
                'space-v1',
                'telegram',
                'default',
                $chatId,
                $threadId,
            ]);
            $spaceId   = 'spc_' . substr(hash('sha256', $canonicalIdentity), 0, 40);
            $bindingId = self::deterministicRecordId(
                'binding:v2:default:telegram:' . $chatId . ':' . ($threadId === '' ? 'root' : $threadId),
            );
            $releaseId            = self::deterministicRecordId('release:v2:' . $spaceId . ':1');
            $model                = 'deepseek/deepseek-v4-pro';
            $capabilityPolicyJson = '{"version":1,"capsuleNetwork":"deny","crossSpaceReads":false}';
            $legacySkills         = $threadId === ''
                ? $this->database()->query(<<<'SQL'
                    SELECT name, description, body, enabled
                    FROM runtime_skills
                    WHERE chat_id = ?
                    ORDER BY name ASC
                    SQL, [$chatId])->fetchAll()
                : [];
            $skillContent = array_map(
                static fn (array $skill): array => [
                    'name'        => (string) $skill['name'],
                    'description' => (string) $skill['description'],
                    'body'        => (string) $skill['body'],
                    'enabled'     => (bool) $skill['enabled'],
                ],
                $legacySkills,
            );
            $manifestJson = json_encode([
                'schemaVersion' => 1,
                'source'        => 'legacy-import',
                'capsules'      => [],
                'skillsDigest'  => 'sha256:' . hash('sha256', json_encode(
                    $skillContent,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $releaseDigest = 'sha256:' . hash('sha256', json_encode([
                'model'             => $model,
                'prompt'            => '',
                'personality'       => [],
                'manifest'          => json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR),
                'capability_policy' => json_decode(
                    $capabilityPolicyJson,
                    true,
                    flags: JSON_THROW_ON_ERROR,
                ),
                'artifact_digest' => null,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            $this->database()->execute(<<<'SQL'
                INSERT INTO agent_spaces (
                    id, status, active_release_id, release_generation, memory_revision,
                    dream_enabled, dream_time_zone, last_dream_at, created_at, updated_at
                ) VALUES (?, 'active', NULL, 0, 0, true, 'Asia/Yekaterinburg', NULL, ?, ?)
                SQL, [$spaceId, $now, $now]);
            $this->database()->execute(<<<'SQL'
                INSERT INTO space_bindings (
                    id, space_id, bot_instance_id, platform,
                    external_conversation_id, external_thread_id, created_at
                ) VALUES (?, ?, 'default', 'telegram', ?, ?, ?)
                SQL, [$bindingId, $spaceId, $chatId, $threadId, $now]);
            $this->database()->execute(<<<'SQL'
                INSERT INTO space_releases (
                    id, space_id, parent_release_id, source_proposal_id,
                    sequence, status, release_digest,
                    model, prompt, personality_json, manifest_json, capability_policy_json,
                    artifact_digest, evaluation_digest, created_by, created_at, activated_at
                ) VALUES (
                    ?, ?, NULL, NULL, 1, 'active', ?, ?, '', '{}', ?, ?,
                    NULL, NULL, 'legacy-import', ?, ?
                )
                SQL, [
                $releaseId,
                $spaceId,
                $releaseDigest,
                $model,
                $manifestJson,
                $capabilityPolicyJson,
                $now,
                $now,
            ]);
            $this->database()->execute(<<<'SQL'
                UPDATE agent_spaces
                SET active_release_id = ?, release_generation = 1, updated_at = ?
                WHERE id = ?
                SQL, [$releaseId, $now, $spaceId]);
        }

        $this->database()->execute(<<<'SQL'
            WITH mapped AS (
                SELECT
                    skill.*,
                    binding.space_id,
                    release.id AS release_id,
                    md5('skill:v2:' || binding.space_id || ':' || skill.id::text) AS skill_hash
                FROM runtime_skills AS skill
                JOIN space_bindings AS binding
                    ON binding.bot_instance_id = 'default'
                    AND binding.platform = 'telegram'
                    AND binding.external_conversation_id = skill.chat_id::text
                    AND binding.external_thread_id = ''
                JOIN space_releases AS release
                    ON release.space_id = binding.space_id AND release.sequence = 1
            )
            INSERT INTO space_skill_versions (
                id, space_id, release_id, name, version, description, body,
                manifest_json, source_digest, enabled, created_at
            )
            SELECT
                substr(skill_hash, 1, 8) || '-' || substr(skill_hash, 9, 4) || '-4'
                    || substr(skill_hash, 14, 3) || '-a' || substr(skill_hash, 18, 3)
                    || '-' || substr(skill_hash, 21, 12),
                space_id, release_id, name, 1, description, body,
                json_build_object('source', 'runtime_skills', 'legacy_id', id)::text,
                md5(body), enabled, created_at
            FROM mapped
            SQL);

        $this->database()->execute(<<<'SQL'
            WITH mapped AS (
                SELECT
                    memory.*,
                    binding.space_id,
                    row_number() OVER (
                        PARTITION BY binding.space_id
                        ORDER BY memory.updated_at, memory.id
                    ) AS revision,
                    md5('memory:v2:' || binding.space_id || ':' || memory.id::text) AS memory_hash
                FROM participant_memories AS memory
                JOIN space_bindings AS binding
                    ON binding.bot_instance_id = 'default'
                    AND binding.platform = 'telegram'
                    AND binding.external_conversation_id = memory.chat_id::text
                    AND binding.external_thread_id = ''
            )
            INSERT INTO space_memory_versions (
                id, space_id, revision, participant_key, participant_label,
                memory, quote, context, status, supersedes_memory_id,
                idempotency_key, provenance_json, confidence_permille, created_at, source_updated_at
            )
            SELECT
                substr(memory_hash, 1, 8) || '-' || substr(memory_hash, 9, 4) || '-4'
                    || substr(memory_hash, 14, 3) || '-a' || substr(memory_hash, 18, 3)
                    || '-' || substr(memory_hash, 21, 12),
                space_id, revision, participant_key, participant_label,
                memory, quote, context, 'active', NULL, NULL,
                json_build_object('source', 'participant_memories', 'legacy_id', id)::text,
                NULL, created_at, updated_at
            FROM mapped
            SQL);

        $this->database()->execute(<<<'SQL'
            UPDATE agent_spaces AS space
            SET memory_revision = revisions.max_revision
            FROM (
                SELECT space_id, max(revision) AS max_revision
                FROM space_memory_versions
                GROUP BY space_id
            ) AS revisions
            WHERE revisions.space_id = space.id
            SQL);
    }
}
