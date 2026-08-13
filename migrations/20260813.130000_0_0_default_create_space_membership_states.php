<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

final class OrmDefaultCreateSpaceMembershipStates20260813130000 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->database()->execute(<<<'SQL'
            CREATE TABLE space_membership_states (
                bot_instance_id text NOT NULL,
                platform text NOT NULL,
                external_conversation_id text NOT NULL,
                last_update_id bigint NOT NULL CHECK (last_update_id >= 0),
                membership_status text NOT NULL CHECK (
                    membership_status IN ('creator', 'administrator', 'member', 'left', 'kicked')
                ),
                active boolean NOT NULL,
                event_at bigint NOT NULL CHECK (event_at >= 0),
                updated_at bigint NOT NULL CHECK (updated_at >= 0),
                PRIMARY KEY (bot_instance_id, platform, external_conversation_id)
            )
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE INDEX space_membership_states_index_platform_active
            ON space_membership_states (platform, active)
            SQL);
    }

    public function down(): void
    {
        $this->database()->execute('DROP TABLE space_membership_states');
    }
}
