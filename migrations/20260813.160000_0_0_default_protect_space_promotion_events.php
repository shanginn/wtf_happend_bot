<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

/** Promotion events are an append-only authority and activation ledger. */
final class OrmDefaultProtectSpacePromotionEvents20260813160000 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->database()->execute(<<<'SQL'
            CREATE FUNCTION reject_space_promotion_event_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'space promotion events are immutable';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE TRIGGER space_promotion_events_immutable
            BEFORE UPDATE OR DELETE ON space_promotion_events
            FOR EACH ROW EXECUTE FUNCTION reject_space_promotion_event_mutation()
            SQL);
    }

    public function down(): void
    {
        $this->database()->execute('DROP TRIGGER space_promotion_events_immutable ON space_promotion_events');
        $this->database()->execute('DROP FUNCTION reject_space_promotion_event_mutation()');
    }
}
