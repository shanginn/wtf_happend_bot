<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

/** Persist operator pause state across release-qualified Temporal cutovers. */
final class OrmDefaultAddSpaceAgentControls20260821120000 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->database()->execute(<<<'SQL'
            ALTER TABLE agent_spaces
            ADD COLUMN agent_paused boolean NOT NULL DEFAULT false
            SQL);

        // This repair release must not silently re-enable any existing bot.
        // A Telegram owner/admin can explicitly opt a Space back in with /resume.
        $this->database()->execute(<<<'SQL'
            UPDATE agent_spaces
            SET agent_paused = true, updated_at = EXTRACT(EPOCH FROM NOW())::bigint
            WHERE status = 'active'
            SQL);
    }

    public function down(): void
    {
        $this->database()->execute('ALTER TABLE agent_spaces DROP COLUMN agent_paused');
    }
}
