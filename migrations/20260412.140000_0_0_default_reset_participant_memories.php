<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

class OrmDefaultResetParticipantMemories20260412140000 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->database()->execute('DROP TABLE IF EXISTS participant_memories CASCADE');
        $this->database()->execute(<<<'SQL'
            CREATE TABLE participant_memories (
                id serial PRIMARY KEY,
                chat_id bigint NOT NULL,
                participant_key text NOT NULL,
                participant_label text NOT NULL,
                memory text NOT NULL,
                quote text NOT NULL,
                context text NOT NULL,
                created_at bigint NOT NULL,
                updated_at bigint NOT NULL
            )
            SQL);
        $this->database()->execute(
            'CREATE INDEX participant_memories_index_chat_id_participant_key ON participant_memories (chat_id, participant_key)'
        );
    }

    public function down(): void
    {
        $this->database()->execute('DROP TABLE IF EXISTS participant_memories CASCADE');
    }
}
