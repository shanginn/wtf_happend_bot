<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

final class OrmDefaultCreateSpaceRuntimeSnapshots20260812160000 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->database()->execute(<<<'SQL'
            CREATE TABLE space_runtime_snapshots (
                id text PRIMARY KEY,
                space_id text NOT NULL REFERENCES agent_spaces (id),
                batch_id text NOT NULL,
                release_id text NOT NULL,
                release_generation bigint NOT NULL,
                memory_revision bigint NOT NULL,
                payload_json text NOT NULL,
                created_at bigint NOT NULL,
                CONSTRAINT space_runtime_snapshots_unique_batch UNIQUE (space_id, batch_id),
                CONSTRAINT space_runtime_snapshots_release_same_space_fk
                    FOREIGN KEY (release_id, space_id)
                    REFERENCES space_releases (id, space_id)
            )
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE INDEX space_runtime_snapshots_index_release_id
            ON space_runtime_snapshots (release_id)
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE FUNCTION reject_space_runtime_snapshot_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'space runtime snapshots are immutable';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE TRIGGER space_runtime_snapshots_no_update
            BEFORE UPDATE ON space_runtime_snapshots
            FOR EACH ROW EXECUTE FUNCTION reject_space_runtime_snapshot_update()
            SQL);
    }

    public function down(): void
    {
        $this->database()->execute('DROP TABLE space_runtime_snapshots');
        $this->database()->execute('DROP FUNCTION reject_space_runtime_snapshot_update()');
    }
}
